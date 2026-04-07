<?php

namespace App\Tests;

use App\Entity\SqlClient;
use App\RepositoryPDO\DatabaseSchemaRepository;

/**
 * Description of DatabaseSchemaRepositoryTest.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class DatabaseSchemaRepositoryTest extends MyKernelTestCase
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

    public function testGetVersion(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName(self::SERVER_NAME);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $res = $databaseRepositoryPdo->getVersion();

        $this->assertIsString($res['@@version']);
    }

    public function testShowDatabases(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName(self::SERVER_NAME);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        // dump($databaseRepositoryPdo->showDatabases());
        $this->assertIsArray($databaseRepositoryPdo->showDatabases());
    }

    public function testShowTables(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName(self::SERVER_NAME);
        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $allDatabase = $databaseRepositoryPdo->showDatabases();
        if (in_array(self::DB_NAME_TESTING_ONE, $allDatabase)) {
            $databaseRepositoryPdo->useDbName(self::DB_NAME_TESTING_ONE);
        }

        // dump($databaseRepositoryPdo->showTables());
        $this->assertIsArray($databaseRepositoryPdo->showTables());
    }

    public function testCreateDbUserAndDrop(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName(self::SERVER_NAME);

        $dbName = self::DB_NAME_TESTING_ONE;
        $username = self::DB_NAME_USER;
        $password = self::DB_NAME_PASSWORD;

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $setupDropDatabase = $databaseRepositoryPdo->dropDatabase($dbName);
        $this->assertTrue($setupDropDatabase);
        $setupDropUsername = $databaseRepositoryPdo->dropUser($username);
        $this->assertTrue($setupDropUsername);

        $create = $databaseRepositoryPdo->createDatabase($dbName);
        $this->assertTrue($create);
        $createUser = $databaseRepositoryPdo->createUser($username, $password);
        $this->assertTrue($createUser);
        $grant = $databaseRepositoryPdo->grantPrivileges($dbName, $username);
        $this->assertTrue($grant);
        $flushPrivileges = $databaseRepositoryPdo->flushPrivileges();
        $this->assertTrue($flushPrivileges);
        $dropDatabase = $databaseRepositoryPdo->dropDatabase($dbName);
        $this->assertTrue($dropDatabase);
        $dropUsername = $databaseRepositoryPdo->dropUser($username);
        $this->assertTrue($dropUsername);
    }

    public function testDtoInnoDbStatus(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName(self::SERVER_NAME);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $res = $databaseRepositoryPdo->showEngineInnodbStatus();
        dump($res);
        $this->assertTrue(true);
    }

    public function testProcessList(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName(self::SERVER_NAME);
        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $processListArray = $databaseRepositoryPdo->showProcessList();
        // dump($processListArray);

        $this->assertIsArray($processListArray);
    }
}
