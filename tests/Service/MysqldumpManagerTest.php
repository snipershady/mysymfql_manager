<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\BackupDump;
use App\Entity\AppUser;
use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
use App\Service\MysqldumpManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;

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
        $this->backupDir = sys_get_temp_dir() . '/mysqldump_manager_test_' . bin2hex(random_bytes(6));
    }

    /**
     * For the tests that actually run mysqldump: the others (validation, listing, relocation) need neither
     * the binary nor proc_open().
     */
    private function requireMysqldump(): void
    {
        $disabled = array_map(trim(...), explode(',', (string) \ini_get('disable_functions')));
        if (\in_array('proc_open', $disabled, strict: true)) {
            self::markTestSkipped('proc_open() is disabled in this environment (required by Symfony Process).');
        }
        if (null === new ExecutableFinder()->find('mysqldump')) {
            self::markTestSkipped('mysqldump is not installed.');
        }
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
    }

    private function manager(): MysqldumpManager
    {
        return new MysqldumpManager($this->backupDir);
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
        $this->requireMysqldump();
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
        $this->requireMysqldump();
        mkdir($this->backupDir, 0777, recursive: true);

        // Connection refused: mysqldump fails after the shell has created the output file.
        $result = $this->manager()->createBackup($this->persistedSqlClient(7)->setHost('127.0.0.1')->setPort(1), 'shop');

        $this->assertFalse($result['is_valid']);
        $this->assertSame([], glob($this->backupDir . '/7/shop/*') ?: []);
        $this->assertStringEndsNotWith('.part', $result['backup_filename']);
    }

    public function testFailedDumpReportsTheMysqldumpErrorOutput(): void
    {
        $this->requireMysqldump();
        $result = $this->manager()->createBackup($this->persistedSqlClient(7)->setHost('127.0.0.1')->setPort(1), 'shop');

        $this->assertFalse($result['is_valid']);
        // e.g. "mysqldump: Got error: 2003: Can't connect to MySQL server on '127.0.0.1:1' (111) when trying to connect"
        $this->assertStringContainsString('2003', $result['msg']);
        $this->assertNotSame([], $result['output']);
    }

    public function testMissingClientBinariesAreReportedInsteadOfBreakingTheService(): void
    {
        $dump = $this->writeDump('7/shop/bkp_2024-01-01_00-00-01_shop_full.sql');
        $previousPath = getenv('PATH');
        putenv('PATH=' . $this->backupDir); // no mysqldump/mysql in there

        try {
            $manager = $this->manager(); // the constructor no longer looks for mysqldump
            $backup = $manager->createBackup($this->persistedSqlClient(7), 'shop');
            $restore = $manager->restoreBackup($this->persistedSqlClient(7), 'shop', $dump);
        } finally {
            putenv(false === $previousPath ? 'PATH' : 'PATH=' . $previousPath);
        }

        $this->assertFalse($backup['is_valid']);
        $this->assertStringContainsString('mysqldump command was not found', $backup['msg']);
        $this->assertSame([], glob($this->backupDir . '/7/shop/*.part') ?: []);
        $this->assertFalse($restore['is_valid']);
        $this->assertStringContainsString('mysql command was not found', $restore['msg']);
    }

    public function testLegacyDumpIsRelocatedToTheServerAndDatabaseFolder(): void
    {
        $this->writeDump('bkp_2024-01-01_00-00-01_shop_full.sql');
        $this->writeDump('3/shop/bkp_2024-01-01_00-00-02_shop_full.sql');
        $manager = $this->manager();

        $legacy = $manager->listLegacyBackups();
        $this->assertSame(['bkp_2024-01-01_00-00-01_shop_full.sql'], array_map(static fn (BackupDump $b): string => $b->id, $legacy));

        $target = $manager->relocateLegacyBackup($legacy[0], $this->persistedSqlClient(3), 'shop');

        $this->assertSame($this->backupDir . '/3/shop/bkp_2024-01-01_00-00-01_shop_full.sql', $target);
        $this->assertFileDoesNotExist($this->backupDir . '/bkp_2024-01-01_00-00-01_shop_full.sql');
        $this->assertSame([], $manager->listLegacyBackups());
        // Now visible to the owners of (3, shop).
        $this->assertSame(3, array_values(array_filter($manager->listAllBackups(), static fn (BackupDump $b): bool => str_ends_with($b->id, '00-00-01_shop_full.sql')))[0]->sqlClientId);
    }

    public function testRelocationNeverOverwritesAnExistingDump(): void
    {
        $this->writeDump('bkp_2024-01-01_00-00-01_shop_full.sql');
        $this->writeDump('3/shop/bkp_2024-01-01_00-00-01_shop_full.sql');
        $legacy = $this->manager()->listLegacyBackups();

        $this->expectException(\RuntimeException::class);
        try {
            $this->manager()->relocateLegacyBackup($legacy[0], $this->persistedSqlClient(3), 'shop');
        } finally {
            $this->assertFileExists($this->backupDir . '/bkp_2024-01-01_00-00-01_shop_full.sql');
        }
    }

    public function testOnlyLegacyDumpsCanBeRelocated(): void
    {
        $this->writeDump('3/shop/bkp_2024-01-01_00-00-02_shop_full.sql');
        $structured = $this->manager()->listAllBackups()[0];

        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->relocateLegacyBackup($structured, $this->persistedSqlClient(4), 'shop');
    }

    private function writeDump(string $relativePath, int $mtime = 1_700_000_000): string
    {
        $path = $this->backupDir . '/' . $relativePath;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0777, recursive: true);
        }
        file_put_contents($path, '-- dump');
        touch($path, $mtime);

        return $path;
    }

    private function persistedSqlClient(int $id): SqlClient
    {
        $sqlClient = $this->sqlClient();
        new \ReflectionProperty(SqlClient::class, 'id')->setValue($sqlClient, $id);

        return $sqlClient;
    }
}
