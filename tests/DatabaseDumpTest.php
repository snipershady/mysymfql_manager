<?php

namespace App\Tests;

use App\Entity\SqlClient;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Service\MysqldumpManager;

/**
 * Description of DatabaseDumpTest.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class DatabaseDumpTest extends MyKernelTestCase
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

    public function testDumpTable(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);

        $msdm = new MysqldumpManager();

        $dbName = $this->dbNameTestingOne;
        $table = $this->dummyTableOne;
        $check = $msdm->createBackup($sqlClient, $dbName, $table);
        dump($check);
        $this->assertTrue($check['is_valid']);
        $this->assertTrue(true);
    }

    public function testDumpTableAndRestore(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);

        $msdm = new MysqldumpManager();

        $check = $msdm->createBackup($sqlClient, $this->dbNameTestingOne, $this->dummyTableOne);
        dump($check);
        $this->assertTrue($check['is_valid']);
        $backupFilename = $check['backup_filename'];

        $checkRestore = $msdm->restoreBackup($sqlClient, $this->dbNameTestingTwo, $backupFilename);
        dump($checkRestore);

        $databaseRepositoryPdo->useDbName($this->dbNameTestingTwo);
        $arrayTables = $databaseRepositoryPdo->showTables();
        dump($arrayTables);
        $this->assertIsArray($arrayTables);
        $this->assertSame($this->dummyTableOne, $arrayTables[0]);
        $rowCount = $databaseRepositoryPdo->countTableRow($this->dummyTableOne);
        $this->assertSame($rowCount, 4);
    }

    public function testDumpDatabase(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);

        $msdm = new MysqldumpManager();
        $check = $msdm->createBackup($sqlClient, $this->dbNameTestingOne);
        dump($check);
        $this->assertTrue($check['is_valid']);
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

        $msdm->restoreBackup($sqlClient, $this->dbNameTestingTwo, $backupFilename);

        $databaseRepositoryPdo->useDbName($this->dbNameTestingTwo);
        $arrayTables = $databaseRepositoryPdo->showTables();
        dump($arrayTables);
        $this->assertIsArray($arrayTables);
        $this->assertSame($this->dummyTableOne, $arrayTables[1]);
        $this->assertSame($this->dummyTableTwo, $arrayTables[0]);
        $rowCount = $databaseRepositoryPdo->countTableRow($this->dummyTableOne);
        $this->assertSame($rowCount, 4);

        $rowCount = $databaseRepositoryPdo->countTableRow($this->dummyTableTwo);
        $this->assertSame($rowCount, 6);
    }
}
