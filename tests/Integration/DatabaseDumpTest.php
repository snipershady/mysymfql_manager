<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\SqlClient;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Service\MysqldumpManager;
use App\Tests\MyKernelTestCase;

/**
 * Description of DatabaseDumpTest.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
final class DatabaseDumpTest extends MyKernelTestCase
{
    protected function setUp(): void
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
    protected function tearDown(): void
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
        $this->assertTrue($check['is_valid']);
        $backupFilename = $check['backup_filename'];

        $msdm->restoreBackup($sqlClient, $this->dbNameTestingTwo, $backupFilename);

        $databaseRepositoryPdo->useDbName($this->dbNameTestingTwo);
        $arrayTables = $databaseRepositoryPdo->showTables();
        $this->assertIsArray($arrayTables);
        $this->assertSame($this->dummyTableOne, $arrayTables[0]);
        $rowCount = $databaseRepositoryPdo->countTableRow($this->dummyTableOne);
        $this->assertSame(4, $rowCount);
    }

    public function testDumpDatabase(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);

        $msdm = new MysqldumpManager();
        $check = $msdm->createBackup($sqlClient, $this->dbNameTestingOne);
        $this->assertTrue($check['is_valid']);
    }

    public function testDumpAndRestoreDatabase(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);

        $msdm = new MysqldumpManager();
        $check = $msdm->createBackup($sqlClient, $this->dbNameTestingOne);
        $this->assertTrue($check['is_valid']);

        $backupFilename = $check['backup_filename'];

        $msdm->restoreBackup($sqlClient, $this->dbNameTestingTwo, $backupFilename);

        $databaseRepositoryPdo->useDbName($this->dbNameTestingTwo);
        $arrayTables = $databaseRepositoryPdo->showTables();
        $this->assertIsArray($arrayTables);
        $this->assertSame($this->dummyTableOne, $arrayTables[1]);
        $this->assertSame($this->dummyTableTwo, $arrayTables[0]);
        $rowCount = $databaseRepositoryPdo->countTableRow($this->dummyTableOne);
        $this->assertSame(4, $rowCount);

        $rowCount = $databaseRepositoryPdo->countTableRow($this->dummyTableTwo);
        $this->assertSame(6, $rowCount);
    }

    public function testDumpUsesThePortOfTheSqlClient(): void
    {
        $sqlClient = $this->entityManager->getRepository(SqlClient::class)->findOneByName($this->serverName);
        $msdm = new MysqldumpManager();

        // 127.0.0.1 forces TCP (with "localhost" the client uses the socket and ignores the port, like PDO).
        $overTcp = fn (int $port): SqlClient => $this->copyWithId($sqlClient)->setHost('127.0.0.1')->setPort($port);

        $this->assertTrue($msdm->createBackup($overTcp($sqlClient->getPort()), $this->dbNameTestingOne, $this->dummyTableOne)['is_valid']);
        $this->assertFalse($msdm->createBackup($overTcp(1), $this->dbNameTestingOne, $this->dummyTableOne)['is_valid']);
    }

    private function copyWithId(SqlClient $sqlClient): SqlClient
    {
        $copy = new SqlClient()
            ->setName('copy')
            ->setHost((string) $sqlClient->getHost())
            ->setPort($sqlClient->getPort())
            ->setUsername((string) $sqlClient->getUsername())
            ->setPassword((string) $sqlClient->getPassword());
        new \ReflectionProperty(SqlClient::class, 'id')->setValue($copy, $sqlClient->getId());

        return $copy;
    }
}
