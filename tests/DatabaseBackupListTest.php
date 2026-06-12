<?php

namespace App\Tests;

use App\Entity\SqlClient;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Service\MysqldumpManager;

/**
 * Description of DatabaseBackupListTest.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class DatabaseBackupListTest extends MyKernelTestCase
{
    #[\Override]
    public function setUp(): void
    {
        parent::setUp();
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);

        // Dropp di tutti i possibili elementi esistenti per il testing
        $databaseRepositoryPdo->dropUser($this->dbNameUser);
        $databaseRepositoryPdo->dropDatabase($this->dbNameTestingOne);
        $databaseRepositoryPdo->dropDatabase($this->dbNameTestingTwo);

        // Creo e popolo DB1
        $databaseRepositoryPdo->createDatabase($this->dbNameTestingOne);
        $databaseRepositoryPdo->createUser($this->dbNameUser, $this->dbNamePassword);
        $databaseRepositoryPdo->grantPrivileges($this->dbNameTestingOne, $this->dbNameUser);
        $databaseRepositoryPdo->flushPrivileges();
        $databaseRepositoryPdo->useDbName($this->dbNameTestingOne);
        $databaseRepositoryPdo->createDummyTable();
        $databaseRepositoryPdo->populateDummiTable();
        $databaseRepositoryPdo->createDummyTableTwo();
        $databaseRepositoryPdo->populateDummiTableTwo();

        // Creo e popolo DB2
        $databaseRepositoryPdo->createDatabase($this->dbNameTestingTwo);
        $databaseRepositoryPdo->grantPrivileges($this->dbNameTestingTwo, $this->dbNameUser);
        $databaseRepositoryPdo->flushPrivileges();
    }

    #[\Override]
    public function tearDown(): void
    {
        parent::setUp();
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);
        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $databaseRepositoryPdo->dropUser($this->dbNameUser);
        $databaseRepositoryPdo->dropDatabase($this->dbNameTestingOne);
        $databaseRepositoryPdo->dropDatabase($this->dbNameTestingTwo);
    }

    public function testDumpAndRestoreDatabase(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);

        $msdm = new MysqldumpManager();
        $check = $msdm->createBackup($sqlClient, $this->dbNameTestingOne);
        dump($check);
        $this->assertTrue($check['is_valid']);

        $backupFilename = $check['backup_filename'];

        $list = $msdm->listAllBackups();
        dump($list);
        $this->assertIsArray($list);
        $this->assertGreaterThan(0, count($list));
    }
}
