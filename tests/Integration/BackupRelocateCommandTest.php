<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\BackupRelocateCommand;
use App\Entity\SqlClient;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\RepositoryPDO\DemoDataRepository;
use App\Tests\MyKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:backup-relocate against the real MySQL server and the test BACKUP_PATH.
 */
#[CoversClass(BackupRelocateCommand::class)]
final class BackupRelocateCommandTest extends MyKernelTestCase
{
    private const string FULL = 'bkp_2001-01-01_00-00-01_%s_full.sql';

    private const string TABLE = 'bkp_2001-01-01_00-00-02_%s_%s.sql';

    private const string UNKNOWN = 'bkp_2001-01-01_00-00-03_no_such_db_xyz_full.sql';

    private const string DROPPED = 'bkp_2001-01-01_00-00-04_dropped_db_full.sql';

    private string $backupPath;

    private SqlClient $sqlClient;

    private DatabaseSchemaRepository $remote;

    /** @var list<string> */
    private array $legacyFiles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupPath = (string) $_ENV['BACKUP_PATH'];
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0777, recursive: true);
        }
        $sqlClient = $this->entityManager->getRepository(SqlClient::class)->findOneByName($this->serverName);
        $this->assertInstanceOf(SqlClient::class, $sqlClient);
        $this->sqlClient = $sqlClient;
        $this->remote = new DatabaseSchemaRepository($sqlClient);
        $remoteDemoData = new DemoDataRepository($sqlClient);
        $this->remote->dropDatabase($this->dbNameTestingOne);
        $this->remote->createDatabase($this->dbNameTestingOne);
        $this->remote->useDbName($this->dbNameTestingOne);

        $remoteDemoData->createDummyTable();

        $this->legacyFiles = [
            \sprintf(self::FULL, $this->dbNameTestingOne),
            \sprintf(self::TABLE, $this->dbNameTestingOne, $this->dummyTableOne),
            self::UNKNOWN,
            self::DROPPED,
        ];
        foreach ($this->legacyFiles as $file) {
            file_put_contents($this->backupPath . '/' . $file, '-- legacy dump');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->legacyFiles as $file) {
            foreach (glob($this->backupPath . '/{,*/*/}' . $file, \GLOB_BRACE) ?: [] as $path) {
                unlink($path);
            }
        }
        $this->remote->dropDatabase($this->dbNameTestingOne);
        parent::tearDown();
    }

    public function testDryRunShowsThePlanAndMovesNothing(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['name' => $this->serverName]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('would move', $display);
        $this->assertStringContainsString('no matching database', $display);
        $this->assertStringContainsString('Nothing has been moved', $display);
        foreach ($this->legacyFiles as $file) {
            $this->assertFileExists($this->backupPath . '/' . $file);
        }
    }

    public function testForceMovesMatchedDumpsAndLeavesTheOthers(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['name' => $this->serverName, '--db' => ['dropped_db'], '--force' => true]));

        $id = $this->sqlClient->getId();
        $this->assertFileExists(\sprintf('%s/%d/%s/%s', $this->backupPath, $id, $this->dbNameTestingOne, $this->legacyFiles[0]));
        $this->assertFileExists(\sprintf('%s/%d/%s/%s', $this->backupPath, $id, $this->dbNameTestingOne, $this->legacyFiles[1]));
        $this->assertFileExists(\sprintf('%s/%d/dropped_db/%s', $this->backupPath, $id, self::DROPPED));
        $this->assertFileExists($this->backupPath . '/' . self::UNKNOWN);
        $this->assertStringContainsString('3 file(s) moved, 1 left in place.', $tester->getDisplay());
    }

    public function testUnknownServerFails(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute(['name' => 'no_such_server']));
        $this->assertFileExists($this->backupPath . '/' . self::UNKNOWN);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new Application(self::$kernel)->find('app:backup-relocate'));
    }
}
