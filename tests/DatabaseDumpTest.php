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
        $sqlClient = $sqlClientRepository->findOneByName(self::SERVER_NAME);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);

        // Dropp di tutti i possibili elementi esistenti per il testing
        $databaseRepositoryPdo->dropUser(self::DB_NAME_USER);
        $databaseRepositoryPdo->dropDatabase(self::DB_NAME_TESTING_ONE);
        $databaseRepositoryPdo->dropDatabase(self::DB_NAME_TESTING_TWO);

        // Creo e popolo DB1
        $databaseRepositoryPdo->createDatabase(self::DB_NAME_TESTING_ONE);
        $databaseRepositoryPdo->createUser(self::DB_NAME_USER, self::DB_NAME_PASSWORD);
        $databaseRepositoryPdo->grantPrivileges(self::DB_NAME_TESTING_ONE, self::DB_NAME_USER);
        $databaseRepositoryPdo->flushPrivileges();
        $databaseRepositoryPdo->useDbName(self::DB_NAME_TESTING_ONE);
        $databaseRepositoryPdo->createDummyTable();
        $databaseRepositoryPdo->populateDummiTable();
        $databaseRepositoryPdo->createDummyTableTwo();
        $databaseRepositoryPdo->populateDummiTableTwo();

        // Creo e popolo DB2
        $databaseRepositoryPdo->createDatabase(self::DB_NAME_TESTING_TWO);
        $databaseRepositoryPdo->grantPrivileges(self::DB_NAME_TESTING_TWO, self::DB_NAME_USER);
        $databaseRepositoryPdo->flushPrivileges();
    }

    #[\Override]
    public function tearDown(): void
    {
        parent::setUp();
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName(self::SERVER_NAME);
        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $databaseRepositoryPdo->dropUser(self::DB_NAME_USER);
        $databaseRepositoryPdo->dropDatabase(self::DB_NAME_TESTING_ONE);
        $databaseRepositoryPdo->dropDatabase(self::DB_NAME_TESTING_TWO);
    }

    public function testDumpTable(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName(self::SERVER_NAME);

        $msdm = new MysqldumpManager();

        $dbName = self::DB_NAME_TESTING_ONE;
        $table = self::DUMMY_TABLE_ONE;
        $check = $msdm->createBackup($sqlClient, $dbName, $table);
        dump($check);
        $this->assertTrue($check['is_valid']);
        $this->assertTrue(true);
    }

    public function testDumpTableAndRestore(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName(self::SERVER_NAME);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);

        $msdm = new MysqldumpManager();

        $check = $msdm->createBackup($sqlClient, self::DB_NAME_TESTING_ONE, self::DUMMY_TABLE_ONE);
        dump($check);
        $this->assertTrue($check['is_valid']);
        $backupFilename = $check['backup_filename'];

        $checkRestore = $msdm->restoreBackup($sqlClient, self::DB_NAME_TESTING_TWO, $backupFilename);
        dump($checkRestore);

        $databaseRepositoryPdo->useDbName(self::DB_NAME_TESTING_TWO);
        $arrayTables = $databaseRepositoryPdo->showTables();
        dump($arrayTables);
        $this->assertIsArray($arrayTables);
        $this->assertSame(self::DUMMY_TABLE_ONE, $arrayTables[0]);
        $rowCount = $databaseRepositoryPdo->countTableRow(self::DUMMY_TABLE_ONE);
        $this->assertSame($rowCount, 4);
    }

    public function testDumpDatabase(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName(self::SERVER_NAME);

        $msdm = new MysqldumpManager();
        $check = $msdm->createBackup($sqlClient, self::DB_NAME_TESTING_ONE);
        dump($check);
        $this->assertTrue($check['is_valid']);
    }

    public function testDumpAndRestoreDatabase(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName(self::SERVER_NAME);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);

        $msdm = new MysqldumpManager();
        $check = $msdm->createBackup($sqlClient, self::DB_NAME_TESTING_ONE);
        dump($check);
        $this->assertTrue($check['is_valid']);

        $backupFilename = $check['backup_filename'];

        $msdm->restoreBackup($sqlClient, self::DB_NAME_TESTING_TWO, $backupFilename);

        $databaseRepositoryPdo->useDbName(self::DB_NAME_TESTING_TWO);
        $arrayTables = $databaseRepositoryPdo->showTables();
        dump($arrayTables);
        $this->assertIsArray($arrayTables);
        $this->assertSame(self::DUMMY_TABLE_ONE, $arrayTables[1]);
        $this->assertSame(self::DUMMY_TABLE_TWO, $arrayTables[0]);
        $rowCount = $databaseRepositoryPdo->countTableRow(self::DUMMY_TABLE_ONE);
        $this->assertSame($rowCount, 4);

        $rowCount = $databaseRepositoryPdo->countTableRow(self::DUMMY_TABLE_TWO);
        $this->assertSame($rowCount, 6);
    }
}
