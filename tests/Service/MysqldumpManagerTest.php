<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Service\MysqldumpManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see MysqldumpManager} that do not require a live MySQL
 * server: constructor guards, input validation and the filesystem listing
 * helpers.
 */
final class MysqldumpManagerTest extends TestCase
{
    private string $backupDir;

    #[\Override]
    protected function setUp(): void
    {
        $disabled = array_map(trim(...), explode(',', (string) \ini_get('disable_functions')));
        if (\in_array('exec', $disabled, true) || \in_array('shell_exec', $disabled, true)) {
            self::markTestSkipped('exec()/shell_exec() are disabled in this environment.');
        }
        if (!shell_exec('which mysqldump 2>/dev/null')) {
            self::markTestSkipped('mysqldump is not installed.');
        }

        $this->backupDir = sys_get_temp_dir() . '/mysqldump_manager_test_' . bin2hex(random_bytes(6));
        $_ENV['BACKUP_PATH'] = $this->backupDir;
        $_SERVER['BACKUP_PATH'] = $this->backupDir;
        putenv('BACKUP_PATH=' . $this->backupDir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_dir($this->backupDir)) {
            foreach (glob($this->backupDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->backupDir);
        }
        unset($_ENV['BACKUP_PATH'], $_SERVER['BACKUP_PATH']);
        putenv('BACKUP_PATH');
    }

    private function manager(): MysqldumpManager
    {
        return new MysqldumpManager();
    }

    private function sqlClient(): SqlClient
    {
        return new SqlClient()
            ->setName('primary')
            ->setHost('127.0.0.1')
            ->setUsername('root')
            ->setPassword('secret')
            ->setPort(3306);
    }

    public function testCreateBackupRejectsAnInvalidDatabaseName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->createBackup($this->sqlClient(), 'bad name; DROP');
    }

    public function testCreateBackupRejectsAnInvalidTableName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->createBackup($this->sqlClient(), 'valid_db', 'bad-table!');
    }

    public function testRestoreBackupRejectsAnInvalidDatabaseName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->restoreBackup($this->sqlClient(), 'bad;name', '/tmp/whatever.sql');
    }

    public function testRestoreBackupRejectsFilesOutsideTheBackupDirectory(): void
    {
        mkdir($this->backupDir, 0777, true);
        $outside = tempnam(sys_get_temp_dir(), 'not_a_backup_');
        self::assertIsString($outside);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->manager()->restoreBackup($this->sqlClient(), 'valid_db', $outside);
        } finally {
            @unlink($outside);
        }
    }

    public function testListAllBackupsReturnsEmptyArrayWhenDirectoryIsMissing(): void
    {
        $this->assertSame([], $this->manager()->listAllBackups());
    }

    public function testListBackupsReturnsEmptyArrayForANonAdminWithoutOwnedDatabases(): void
    {
        mkdir($this->backupDir, 0777, true);
        file_put_contents($this->backupDir . '/bkp_2024-01-01_00-00-00_shop_full.sql', '-- dump');

        $user = new AppUser()->setUsername('alice')->setEmail('alice@example.com')->setRoles(['ROLE_USER']);

        $this->assertSame([], $this->manager()->listBackups($user, []));
    }

    public function testListBackupsReturnsMatchingDumpsForAnAdmin(): void
    {
        mkdir($this->backupDir, 0777, true);
        $path = $this->backupDir . '/bkp_2024-01-01_00-00-00_shop_full.sql';
        file_put_contents($path, '-- dump');

        $admin = new AppUser()->setUsername('root')->setEmail('root@example.com')->setRoles(['ROLE_ADMIN']);

        $backups = $this->manager()->listBackups($admin, []);

        $this->assertCount(1, $backups);
        $this->assertSame('bkp_2024-01-01_00-00-00_shop_full.sql', $backups[0]->filename);
        $this->assertSame($path, $backups[0]->path);
    }
}
