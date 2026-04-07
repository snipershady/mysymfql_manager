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

        $list = $msdm->listAllBackups();
        dump($list);
        $this->assertIsArray($list);
        $this->assertGreaterThan(0, count($list));
    }
}
