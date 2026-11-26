<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SetupCommand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * First run of app:setup (writes .env.local) in a temporary project directory.
 */
#[CoversClass(SetupCommand::class)]
final class SetupCommandTest extends TestCase
{
    private string $projectDir;

    #[\Override]
    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/setup_command_test_' . bin2hex(random_bytes(6));
        mkdir($this->projectDir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->projectDir . '/.env.local');
        rmdir($this->projectDir);
    }

    public function testFirstRunWritesEnvLocalKeepingThePasswordVerbatim(): void
    {
        $tester = $this->tester();
        $tester->setInputs([
            ' mysymfql ',          // APP_DB_NAME (trimmed)
            'localhost',           // APP_DB_HOSTNAME
            '',                    // APP_DB_PORT: default 3306
            'manager',             // APP_DB_USER
            '  pa ss  ',           // APP_DB_PASSWORD: never trimmed
            '/var/backups/mysql',  // BACKUP_PATH
            '',                    // MAILER_DSN: default
        ]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        // Read back exactly as Symfony will at boot.
        $env = new Dotenv()->parse((string) file_get_contents($this->projectDir . '/.env.local'));
        $this->assertSame('mysymfql', $env['APP_DB_NAME']);
        $this->assertSame('3306', $env['APP_DB_PORT']);
        $this->assertSame('  pa ss  ', $env['APP_DB_PASSWORD']);
        $this->assertSame('/var/backups/mysql', $env['BACKUP_PATH']);
        $this->assertSame('null://null', $env['MAILER_DSN']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $env['APP_SECRET']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $env['SQLCLIENT_ENCRYPTION_KEY']);
    }

    public function testPasswordWithDotenvSpecialCharactersSurvivesTheRoundTrip(): void
    {
        $password = 'a#b"c$HOME\\d\'e';
        $tester = $this->tester();
        $tester->setInputs(['db', 'localhost', '', 'manager', $password, '/backups', '']);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        $env = new Dotenv()->parse((string) file_get_contents($this->projectDir . '/.env.local'));
        $this->assertSame($password, $env['APP_DB_PASSWORD']);
    }

    public function testEmptyRequiredAnswerIsRejectedUntilAValueIsGiven(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['', 'mysymfql', 'localhost', '', 'manager', 'secret', '/backups', '']);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Database name cannot be empty.', $tester->getDisplay());
        $this->assertSame('mysymfql', new Dotenv()->parse((string) file_get_contents($this->projectDir . '/.env.local'))['APP_DB_NAME']);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new SetupCommand(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(Connection::class),
            $this->createStub(UserPasswordHasherInterface::class),
            $this->projectDir,
        ));
    }
}
