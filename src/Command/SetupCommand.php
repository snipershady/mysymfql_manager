<?php

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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Process\Process;

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
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectDir = $this->getApplication()->getKernel()->getProjectDir();
        $envLocalPath = $projectDir.'/.env.local';

        if ($this->needsEnvSetup($envLocalPath)) {
            $result = $this->setupEnv($input, $output, $io, $envLocalPath);

            if (Command::SUCCESS !== $result) {
                return $result;
            }

            $io->note('Re-run the command to complete the setup: php bin/console app:setup');

            return Command::SUCCESS;
        }

        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist(['app_user'])) {
            $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM app_user');

            if ($count > 0) {
                $io->warning('Setup already completed');

                return Command::SUCCESS;
            }
        }

        $io->section('Creating data structure...');

        $consolePath = $projectDir.'/bin/console';
        $php = \PHP_BINARY;

        $diff = new Process([$php, $consolePath, 'doctrine:migrations:diff', '--no-interaction']);
        $diff->run(function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        if (!$diff->isSuccessful()) {
            $io->error('Error during migration generation (diff).');

            return Command::FAILURE;
        }

        $migrate = new Process([$php, $consolePath, 'doctrine:migrations:migrate', '--no-interaction']);
        $migrate->run(function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        if (!$migrate->isSuccessful()) {
            $io->error('Error during migration execution.');

            return Command::FAILURE;
        }

        $io->section('Creating administrator user');

        $helper = $this->getHelper('question');

        $usernameQuestion = new Question('Username: ');
        $usernameQuestion->setValidator(static function (?string $value): string {
            if (empty(trim((string) $value))) {
                throw new \RuntimeException('The username cannot be empty.');
            }

            return trim($value);
        });

        $emailQuestion = new Question('Email: ');
        $emailQuestion->setValidator(static function (?string $value): string {
            if (empty(trim((string) $value))) {
                throw new \RuntimeException('The email cannot be empty.');
            }

            return trim($value);
        });

        $passwordQuestion = new Question('Password: ');
        $passwordQuestion->setHidden(true);
        $passwordQuestion->setHiddenFallback(false);
        $passwordQuestion->setValidator(static function (?string $value): string {
            if (empty($value)) {
                throw new \RuntimeException('The password cannot be empty.');
            }

            return $value;
        });

        $username = $helper->ask($input, $output, $usernameQuestion);
        $email = $helper->ask($input, $output, $emailQuestion);
        $plainPassword = $helper->ask($input, $output, $passwordQuestion);

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

        foreach (['APP_SECRET', 'SQLCLIENT_ENCRYPTION_KEY', 'ALTCHAKEY', 'APP_DB_NAME', 'APP_DB_HOSTNAME', 'APP_DB_USER', 'APP_DB_PASSWORD', 'BACKUP_PATH'] as $key) {
            if (!preg_match('/^'.$key.'=(?!CHANGE\s*$)\S/m', $content)) {
                return true;
            }
        }

        return false;
    }

    private function setupEnv(InputInterface $input, OutputInterface $output, SymfonyStyle $io, string $envLocalPath): int
    {
        $io->section('.env.local configuration');

        // Auto-generate secret keys
        $appSecret = bin2hex(random_bytes(32));
        $encryptionKey = sodium_bin2hex(sodium_crypto_secretbox_keygen());
        $altchaKey = bin2hex(random_bytes(32));

        $io->writeln('Automatically generated keys:');
        $io->writeln(" * APP_SECRET              = $appSecret");
        $io->writeln(" * SQLCLIENT_ENCRYPTION_KEY = $encryptionKey");
        $io->writeln(" * ALTCHAKEY               = $altchaKey");
        $io->newLine();

        // Read existing .env.local to preserve any existing values
        $existing = [];
        if (file_exists($envLocalPath)) {
            foreach (file($envLocalPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with($line, '#')) {
                    continue;
                }
                [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
                $existing[trim($k)] = trim($v);
            }
        }

        $notEmpty = static function (?string $value, string $label): string {
            if (empty(trim((string) $value))) {
                throw new \RuntimeException("$label cannot be empty.");
            }

            return trim($value);
        };

        $io->section('Database configuration');

        $dbName = $io->ask('Database name (APP_DB_NAME)', $this->existingOrNull($existing, 'APP_DB_NAME'), static fn (?string $v) => $notEmpty($v, 'Database name'));
        $dbHost = $io->ask('Database host (APP_DB_HOSTNAME)', $this->existingOrNull($existing, 'APP_DB_HOSTNAME'), static fn (?string $v) => $notEmpty($v, 'Database host'));
        $dbPort = $io->ask('Database port (APP_DB_PORT)', $this->existingOrNull($existing, 'APP_DB_PORT') ?? '3306', static fn (?string $v) => $notEmpty($v, 'Database port'));
        $dbUser = $io->ask('Database user (APP_DB_USER)', $this->existingOrNull($existing, 'APP_DB_USER'), static fn (?string $v) => $notEmpty($v, 'Database user'));
        $dbPass = $io->askHidden('Database password (APP_DB_PASSWORD)', static fn (?string $v) => $notEmpty($v, 'Database password'));
        $backupPath = $io->ask('Backup path (BACKUP_PATH)', $this->existingOrNull($existing, 'BACKUP_PATH'), static fn (?string $v) => $notEmpty($v, 'Backup path'));

        $io->section('Mailer configuration');

        $mailerDefault = $this->existingOrNull($existing, 'MAILER_DSN') ?? 'null://null';
        $mailerDsn = $io->ask('MAILER_DSN (Press Enter to keep the default)', $mailerDefault) ?: $mailerDefault;

        // Build and write .env.local
        $managedKeys = ['APP_SECRET', 'SQLCLIENT_ENCRYPTION_KEY', 'ALTCHAKEY', 'APP_DB_NAME', 'APP_DB_HOSTNAME', 'APP_DB_PORT', 'APP_DB_USER', 'APP_DB_PASSWORD', 'BACKUP_PATH', 'MAILER_DSN'];

        $lines = [
            "APP_SECRET=$appSecret",
            "SQLCLIENT_ENCRYPTION_KEY=$encryptionKey",
            "ALTCHAKEY=$altchaKey",
            "APP_DB_NAME=$dbName",
            "APP_DB_HOSTNAME=$dbHost",
            "APP_DB_PORT=$dbPort",
            "APP_DB_USER=$dbUser",
            "APP_DB_PASSWORD=$dbPass",
            "BACKUP_PATH=$backupPath",
            "MAILER_DSN=$mailerDsn",
        ];

        // Preserve any other existing keys not managed here
        foreach ($existing as $k => $v) {
            if (!in_array($k, $managedKeys, true)) {
                $lines[] = "$k=$v";
            }
        }

        file_put_contents($envLocalPath, implode("\n", $lines)."\n");

        $io->success('.env.local file configured successfully.');

        return Command::SUCCESS;
    }

    private function existingOrNull(array $existing, string $key): ?string
    {
        $value = $existing[$key] ?? null;

        return ($value && 'CHANGE' !== $value) ? $value : null;
    }
}
