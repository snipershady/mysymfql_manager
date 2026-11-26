<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\BackupDump;
use App\Entity\AppUser;
use App\Entity\DatabaseOwner;
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

    /**
     * @var array{env: mixed, server: mixed, getenv: string|false}
     */
    private array $previousBackupPath;

    #[\Override]
    protected function setUp(): void
    {
        $disabled = array_map(trim(...), explode(',', (string) \ini_get('disable_functions')));
        if (\in_array('exec', $disabled, strict: true) || \in_array('shell_exec', $disabled, strict: true)) {
            self::markTestSkipped('exec()/shell_exec() are disabled in this environment.');
        }
        if (!shell_exec('which mysqldump 2>/dev/null')) {
            self::markTestSkipped('mysqldump is not installed.');
        }

        $this->previousBackupPath = [
            'env' => $_ENV['BACKUP_PATH'] ?? null,
            'server' => $_SERVER['BACKUP_PATH'] ?? null,
            'getenv' => getenv('BACKUP_PATH'),
        ];
        $this->backupDir = sys_get_temp_dir() . '/mysqldump_manager_test_' . bin2hex(random_bytes(6));
        $_ENV['BACKUP_PATH'] = $this->backupDir;
        $_SERVER['BACKUP_PATH'] = $this->backupDir;
        putenv('BACKUP_PATH=' . $this->backupDir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_dir($this->backupDir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->backupDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $this->assertInstanceOf(\SplFileInfo::class, $item);
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->backupDir);
        }

        // Restore the previous values: other test classes (integration dumps) read BACKUP_PATH too.
        unset($_ENV['BACKUP_PATH'], $_SERVER['BACKUP_PATH']);
        if (null !== $this->previousBackupPath['env']) {
            $_ENV['BACKUP_PATH'] = $this->previousBackupPath['env'];
        }
        if (null !== $this->previousBackupPath['server']) {
            $_SERVER['BACKUP_PATH'] = $this->previousBackupPath['server'];
        }
        putenv(false === $this->previousBackupPath['getenv'] ? 'BACKUP_PATH' : 'BACKUP_PATH=' . $this->previousBackupPath['getenv']);
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
        mkdir($this->backupDir, 0777, recursive: true);
        $outside = tempnam(sys_get_temp_dir(), 'not_a_backup_');
        $this->assertIsString($outside);

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
        mkdir($this->backupDir, 0777, recursive: true);
        file_put_contents($this->backupDir . '/bkp_2024-01-01_00-00-00_shop_full.sql', '-- dump');

        $user = new AppUser()->setUsername('alice')->setEmail('alice@example.com')->setRoles(['ROLE_USER']);

        $this->assertSame([], $this->manager()->listBackups($user, []));
    }

    public function testListBackupsReturnsMatchingDumpsForAnAdmin(): void
    {
        mkdir($this->backupDir, 0777, recursive: true);
        $path = $this->backupDir . '/bkp_2024-01-01_00-00-00_shop_full.sql';
        file_put_contents($path, '-- dump');

        $admin = new AppUser()->setUsername('root')->setEmail('root@example.com')->setRoles(['ROLE_ADMIN']);

        $backups = $this->manager()->listBackups($admin, []);

        $this->assertCount(1, $backups);
        $this->assertSame('bkp_2024-01-01_00-00-00_shop_full.sql', $backups[0]->filename);
        $this->assertSame($path, $backups[0]->path);
    }

    public function testCreateBackupRequiresAPersistedSqlClient(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->createBackup($this->sqlClient(), 'valid_db');
    }

    public function testNonAdminSeesOnlyDumpsOfTheExactServerAndDatabaseOwned(): void
    {
        $this->writeDump('1/app/bkp_2024-01-01_00-00-01_app_full.sql');
        $this->writeDump('1/app_old/bkp_2024-01-01_00-00-02_app_old_full.sql');
        $this->writeDump('2/app/bkp_2024-01-01_00-00-03_app_full.sql');
        $this->writeDump('bkp_2024-01-01_00-00-04_app_full.sql');

        $user = new AppUser()->setUsername('alice')->setEmail('alice@example.com')->setRoles(['ROLE_USER']);
        $owned = [new DatabaseOwner()->setDbName('app')->setOwner($user)->setSqlClient($this->persistedSqlClient(1))];

        $backups = $this->manager()->listBackups($user, $owned);

        $this->assertSame(['1/app/bkp_2024-01-01_00-00-01_app_full.sql'], array_map(static fn (BackupDump $b): string => $b->id, $backups));
        $this->assertSame(1, $backups[0]->sqlClientId);
        $this->assertSame('app', $backups[0]->dbName);
        $this->assertSame('bkp_2024-01-01_00-00-01_app_full.sql', $backups[0]->filename);
    }

    public function testAdminSeesStructuredAndLegacyDumpsNewestFirst(): void
    {
        $this->writeDump('1/app/bkp_2024-01-01_00-00-01_app_full.sql', mtime: 100);
        $this->writeDump('bkp_2024-01-01_00-00-02_app_full.sql', mtime: 200);

        $admin = new AppUser()->setUsername('root')->setEmail('root@example.com')->setRoles(['ROLE_ADMIN']);
        $backups = $this->manager()->listBackups($admin, []);

        $this->assertSame(
            ['bkp_2024-01-01_00-00-02_app_full.sql', '1/app/bkp_2024-01-01_00-00-01_app_full.sql'],
            array_map(static fn (BackupDump $b): string => $b->id, $backups)
        );
        $this->assertNull($backups[0]->sqlClientId);
        $this->assertNull($backups[0]->dbName);
    }

    public function testUnexpectedDirectoriesAreIgnored(): void
    {
        $this->writeDump('srv/app/bkp_2024-01-01_00-00-01_app_full.sql');
        $this->writeDump('1/bad-name/bkp_2024-01-01_00-00-01_x_full.sql');

        $this->assertSame([], $this->manager()->listAllBackups());
    }

    public function testBackupDirectoriesInheritThePermissionsOfTheBackupRoot(): void
    {
        mkdir($this->backupDir, 0777, recursive: true);
        chmod($this->backupDir, 0o751);

        // The dump itself fails (no server on this port), but the target directories are created first.
        $sqlClient = $this->persistedSqlClient(7)->setHost('127.0.0.1')->setPort(1);
        $this->manager()->createBackup($sqlClient, 'shop');

        $this->assertSame(0o751, fileperms($this->backupDir . '/7') & 0o777);
        $this->assertSame(0o751, fileperms($this->backupDir . '/7/shop') & 0o777);
        chmod($this->backupDir, 0o777);
    }

    public function testFailedDumpLeavesNoFileBehind(): void
    {
        mkdir($this->backupDir, 0777, recursive: true);

        // Connection refused: mysqldump fails after the shell has created the output file.
        $result = $this->manager()->createBackup($this->persistedSqlClient(7)->setHost('127.0.0.1')->setPort(1), 'shop');

        $this->assertFalse($result['is_valid']);
        $this->assertSame([], glob($this->backupDir . '/7/shop/*') ?: []);
        $this->assertStringEndsNotWith('.part', $result['backup_filename']);
    }

    private function writeDump(string $relativePath, int $mtime = 1_700_000_000): void
    {
        $path = $this->backupDir . '/' . $relativePath;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0777, recursive: true);
        }
        file_put_contents($path, '-- dump');
        touch($path, $mtime);
    }

    private function persistedSqlClient(int $id): SqlClient
    {
        $sqlClient = $this->sqlClient();
        new \ReflectionProperty(SqlClient::class, 'id')->setValue($sqlClient, $id);

        return $sqlClient;
    }
}
