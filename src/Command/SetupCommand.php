<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AppUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Process\Process;

/**
 * @see \App\Tests\Command\SetupCommandTest
 */
#[AsCommand(
    name: 'app:setup',
    description: 'Initializes the data structure and creates the first administrator user',
)]
class SetupCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $envLocalPath = $this->projectDir . '/.env.local';

        if ($this->needsEnvSetup($envLocalPath)) {
            $result = $this->setupEnv($io, $envLocalPath);

            if (Command::SUCCESS !== $result) {
                return $result;
            }

            $io->note('Re-run the command to complete the setup: php bin/console app:setup');

            return Command::SUCCESS;
        }

        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist(['app_user'])) {
            $count = $this->connection->fetchOne('SELECT COUNT(*) FROM app_user');

            if (is_numeric($count) && (int) $count > 0) {
                $io->warning('Setup already completed');

                return Command::SUCCESS;
            }
        }

        $io->section('Creating data structure...');

        $consolePath = $this->projectDir . '/bin/console';
        $php = \PHP_BINARY;

        // Migrations are versioned in migrations/: setup only applies them (never generates new ones).
        $migrate = new Process([$php, $consolePath, 'doctrine:migrations:migrate', '--no-interaction']);
        $migrate->run(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        if (!$migrate->isSuccessful()) {
            $io->error('Error during migration execution.');

            return Command::FAILURE;
        }

        $io->section('Creating administrator user');

        $usernameQuestion = new Question('Username: ');
        $usernameQuestion->setValidator(static fn (mixed $value): string => self::requireNonEmpty($value, 'The username'));

        $emailQuestion = new Question('Email: ');
        $emailQuestion->setValidator(static fn (mixed $value): string => self::requireNonEmpty($value, 'The email'));

        $passwordQuestion = new Question('Password: ');
        $passwordQuestion->setHidden(hidden: true);
        $passwordQuestion->setHiddenFallback(fallback: false);
        $passwordQuestion->setTrimmable(trimmable: false);
        // Passwords are never trimmed.
        $passwordQuestion->setValidator(static fn (mixed $value): string => self::requireNonEmpty($value, 'The password', trim: false));

        $username = self::requireNonEmpty($io->askQuestion($usernameQuestion), 'The username');
        $email = self::requireNonEmpty($io->askQuestion($emailQuestion), 'The email');
        $plainPassword = self::requireNonEmpty($io->askQuestion($passwordQuestion), 'The password', trim: false);

        $user = new AppUser();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success('Setup completed successfully, you can now log in');

        return Command::SUCCESS;
    }

    private function needsEnvSetup(string $envLocalPath): bool
    {
        if (!file_exists($envLocalPath)) {
            return true;
        }

        $content = file_get_contents($envLocalPath);
        if (false === $content) {
            return true;
        }

        return array_any(['APP_SECRET', 'SQLCLIENT_ENCRYPTION_KEY', 'ALTCHAKEY', 'APP_DB_NAME', 'APP_DB_HOSTNAME', 'APP_DB_USER', 'APP_DB_PASSWORD', 'BACKUP_PATH'], static fn (string $key): bool => !preg_match('/^' . $key . '=(?!CHANGE\s*$)\S/m', $content));
    }

    private function setupEnv(SymfonyStyle $io, string $envLocalPath): int
    {
        $io->section('.env.local configuration');

        // Auto-generate secret keys
        $appSecret = bin2hex(random_bytes(32));
        $encryptionKey = sodium_bin2hex(sodium_crypto_secretbox_keygen());
        $altchaKey = bin2hex(random_bytes(32));

        $io->writeln('Automatically generated keys:');
        $io->writeln(' * APP_SECRET              = ' . $appSecret);
        $io->writeln(' * SQLCLIENT_ENCRYPTION_KEY = ' . $encryptionKey);
        $io->writeln(' * ALTCHAKEY               = ' . $altchaKey);
        $io->newLine();

        // Read existing .env.local to preserve any existing values
        $existing = [];
        if (file_exists($envLocalPath)) {
            foreach (file($envLocalPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (str_starts_with($line, '#')) {
                    continue;
                }
                [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
                $existing[trim($k)] = trim($v);
            }
        }

        $io->section('Database configuration');

        $ask = static fn (string $question, ?string $default, string $label): string => self::requireNonEmpty(
            $io->ask($question, $default, static fn (mixed $v): string => self::requireNonEmpty($v, $label)),
            $label,
        );

        $dbName = $ask('Database name (APP_DB_NAME)', $this->existingOrNull($existing, 'APP_DB_NAME'), 'Database name');
        $dbHost = $ask('Database host (APP_DB_HOSTNAME)', $this->existingOrNull($existing, 'APP_DB_HOSTNAME'), 'Database host');
        $dbPort = $ask('Database port (APP_DB_PORT)', $this->existingOrNull($existing, 'APP_DB_PORT') ?? '3306', 'Database port');
        $dbUser = $ask('Database user (APP_DB_USER)', $this->existingOrNull($existing, 'APP_DB_USER'), 'Database user');
        // Passwords are never trimmed.
        $dbPassQuestion = new Question('Database password (APP_DB_PASSWORD)');
        $dbPassQuestion->setHidden(hidden: true);
        // Symfony trims every answer unless told otherwise.
        $dbPassQuestion->setTrimmable(trimmable: false);
        $dbPassQuestion->setValidator(static fn (mixed $v): string => self::requireNonEmpty($v, 'Database password', trim: false));

        $dbPass = self::requireNonEmpty($io->askQuestion($dbPassQuestion), 'Database password', trim: false);
        $backupPath = $ask('Backup path (BACKUP_PATH)', $this->existingOrNull($existing, 'BACKUP_PATH'), 'Backup path');

        $io->section('Mailer configuration');

        $mailerDefault = $this->existingOrNull($existing, 'MAILER_DSN') ?? 'null://null';
        $mailerAnswer = $io->ask('MAILER_DSN (Press Enter to keep the default)', $mailerDefault);
        $mailerDsn = \is_string($mailerAnswer) && '' !== trim($mailerAnswer) ? trim($mailerAnswer) : $mailerDefault;

        // Build and write .env.local
        $managedKeys = ['APP_SECRET', 'SQLCLIENT_ENCRYPTION_KEY', 'ALTCHAKEY', 'APP_DB_NAME', 'APP_DB_HOSTNAME', 'APP_DB_PORT', 'APP_DB_USER', 'APP_DB_PASSWORD', 'BACKUP_PATH', 'MAILER_DSN'];

        $values = [
            'APP_SECRET' => $appSecret,
            'SQLCLIENT_ENCRYPTION_KEY' => $encryptionKey,
            'ALTCHAKEY' => $altchaKey,
            'APP_DB_NAME' => $dbName,
            'APP_DB_HOSTNAME' => $dbHost,
            'APP_DB_PORT' => $dbPort,
            'APP_DB_USER' => $dbUser,
            'APP_DB_PASSWORD' => $dbPass,
            'BACKUP_PATH' => $backupPath,
            'MAILER_DSN' => $mailerDsn,
        ];
        $lines = array_map(static fn (string $key, string $value): string => $key . '=' . self::dotenvQuote($value), array_keys($values), $values);

        // Preserve any other existing keys not managed here
        foreach ($existing as $k => $v) {
            if (!\in_array($k, $managedKeys, strict: true)) {
                $lines[] = \sprintf('%s=%s', $k, $v);
            }
        }

        file_put_contents($envLocalPath, implode("\n", $lines) . "\n");

        $io->success('.env.local file configured successfully.');

        return Command::SUCCESS;
    }

    /**
     * @param array<string, string> $existing
     */
    private function existingOrNull(array $existing, string $key): ?string
    {
        $value = $existing[$key] ?? null;

        return ($value && 'CHANGE' !== $value) ? $value : null;
    }

    /**
     * Double-quoted Dotenv value: spaces, "#", quotes and "$" (variable expansion) are kept literally.
     */
    private static function dotenvQuote(string $value): string
    {
        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']) . '"';
    }

    /**
     * Turns a console answer into a non-empty string (validators receive mixed values).
     *
     * @throws \RuntimeException when the answer is not a string or is empty
     */
    private static function requireNonEmpty(mixed $value, string $label, bool $trim = true): string
    {
        // Untrimmed answers still carry the line ending of the input line (Symfony QuestionHelper).
        $string = \is_string($value) ? ($trim ? trim($value) : rtrim($value, "\r\n")) : '';
        if ('' === $string) {
            throw new \RuntimeException($label . ' cannot be empty.');
        }

        return $string;
    }
}
