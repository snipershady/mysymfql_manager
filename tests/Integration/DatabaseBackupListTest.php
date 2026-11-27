<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\SqlClient;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\RepositoryPDO\DemoDataRepository;
use App\RepositoryPDO\MysqlAccountRepository;
use App\Tests\MyKernelTestCase;

/**
 * Description of DatabaseBackupListTest.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
final class DatabaseBackupListTest extends MyKernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);
        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $accounts = new MysqlAccountRepository($sqlClient);
        $demoData = new DemoDataRepository($sqlClient);
        // Dropp di tutti i possibili elementi esistenti per il testing
        $accounts->dropUser($this->dbNameUser);
        $databaseRepositoryPdo->dropDatabase($this->dbNameTestingOne);
        $databaseRepositoryPdo->dropDatabase($this->dbNameTestingTwo);
        // Creo e popolo DB1
        $databaseRepositoryPdo->createDatabase($this->dbNameTestingOne);

        $accounts->createUser($this->dbNameUser, $this->dbNamePassword);
        $accounts->grantPrivileges($this->dbNameTestingOne, $this->dbNameUser);
        $accounts->flushPrivileges();

        $databaseRepositoryPdo->useDbName($this->dbNameTestingOne);
        $demoData->createDummyTable();
        $demoData->populateDummiTable();
        $demoData->createDummyTableTwo();
        $demoData->populateDummiTableTwo();
        // Creo e popolo DB2
        $databaseRepositoryPdo->createDatabase($this->dbNameTestingTwo);
        $accounts->grantPrivileges($this->dbNameTestingTwo, $this->dbNameUser);
        $accounts->flushPrivileges();
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::setUp();
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);
        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $accounts = new MysqlAccountRepository($sqlClient);
        $accounts->dropUser($this->dbNameUser);

        $databaseRepositoryPdo->dropDatabase($this->dbNameTestingOne);
        $databaseRepositoryPdo->dropDatabase($this->dbNameTestingTwo);
    }

    public function testDumpAndRestoreDatabase(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);

        new DatabaseSchemaRepository($sqlClient);

        $msdm = $this->mysqldumpManager();
        $check = $msdm->createBackup($sqlClient, $this->dbNameTestingOne);
        $this->assertTrue($check['is_valid']);

        $backupFilename = $check['backup_filename'];
        $this->assertStringContainsString(\sprintf('/%d/%s/bkp_', $sqlClient->getId(), $this->dbNameTestingOne), $backupFilename);

        $list = $msdm->listAllBackups();
        $this->assertIsArray($list);
        $this->assertGreaterThan(0, \count($list));
    }
}
