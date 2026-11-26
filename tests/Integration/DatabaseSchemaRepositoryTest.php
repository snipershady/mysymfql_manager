<?php

namespace App\Tests\Integration;

use App\Dto\InnodbTransaction;
use App\Entity\SqlClient;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Tests\MyKernelTestCase;
use App\Tests\Support\OpenTransaction;

/**
 * Description of DatabaseSchemaRepositoryTest.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class DatabaseSchemaRepositoryTest extends MyKernelTestCase
{
    #[\Override]
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

    public function testGetVersion(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $res = $databaseRepositoryPdo->getVersion();

        $this->assertIsString($res['@@version']);
    }

    public function testShowDatabases(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $this->assertIsArray($databaseRepositoryPdo->showDatabases());
    }

    public function testShowTables(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);
        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $allDatabase = $databaseRepositoryPdo->showDatabases();
        if (\in_array($this->dbNameTestingOne, $allDatabase)) {
            $databaseRepositoryPdo->useDbName($this->dbNameTestingOne);
        }

        $this->assertIsArray($databaseRepositoryPdo->showTables());
    }

    public function testCreateDbUserAndDrop(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);

        $dbName = $this->dbNameTestingOne;
        $username = $this->dbNameUser;
        $password = $this->dbNamePassword;

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

    public function testHasGlobalPrivilegesIsFalseForAnAccountWithOnlyDatabaseGrants(): void
    {
        $sqlClient = $this->entityManager->getRepository(SqlClient::class)->findOneByName($this->serverName);
        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);

        $this->assertFalse($databaseRepositoryPdo->hasGlobalPrivileges($this->dbNameUser, '%'));
        $this->assertSame(
            [$this->dbNameTestingOne, $this->dbNameTestingTwo],
            array_column($databaseRepositoryPdo->getUserGrantsByDb($this->dbNameUser, '%'), 'db')
        );
    }

    public function testHasGlobalPrivilegesIsTrueForThePanelAccount(): void
    {
        $sqlClient = $this->entityManager->getRepository(SqlClient::class)->findOneByName($this->serverName);
        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $panelAccount = (string) $sqlClient->getUsername();
        $hosts = $databaseRepositoryPdo->getHostByUser($panelAccount);
        $this->assertNotEmpty($hosts);

        // The panel account may exist on several hosts (the suite itself creates TEST_DB_USER@'%'):
        // at least one of them carries the global privileges the panel needs.
        $this->assertTrue(array_any(
            $hosts,
            static fn (string $host): bool => $databaseRepositoryPdo->hasGlobalPrivileges($panelAccount, $host)
        ));
    }

    public function testHasGlobalPrivilegesFailsClosedForAMissingAccount(): void
    {
        $sqlClient = $this->entityManager->getRepository(SqlClient::class)->findOneByName($this->serverName);
        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);

        $this->assertTrue($databaseRepositoryPdo->hasGlobalPrivileges('no_such_account_' . bin2hex(random_bytes(4)), '%'));
    }

    public function testShowInnodbTransactionsListsAnOpenTransaction(): void
    {
        $sqlClient = $this->entityManager->getRepository(SqlClient::class)->findOneByName($this->serverName);
        $repo = new DatabaseSchemaRepository($sqlClient);
        $trx = new OpenTransaction($sqlClient, $this->dbNameTestingOne);

        $find = static fn (): ?InnodbTransaction => array_find($repo->showInnodbTransactions(), static fn (InnodbTransaction $t): bool => $t->threadId === $trx->threadId);

        try {
            $this->assertTrue(OpenTransaction::eventually(static fn (): bool => $find() instanceof InnodbTransaction));
            $found = $find();
            $this->assertInstanceOf(InnodbTransaction::class, $found);
            $this->assertSame('RUNNING', $found->state);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $found->started);
            $this->assertSame(0, $found->rowsLocked);
        } finally {
            $trx->close();
        }

        $this->assertTrue(OpenTransaction::eventually(static fn (): bool => null === $find()), 'The transaction is still listed after ROLLBACK.');
    }

    public function testDtoInnoDbStatus(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $res = $databaseRepositoryPdo->showEngineInnodbStatus();
        $this->assertTrue(true);
    }

    public function testProcessList(): void
    {
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName($this->serverName);
        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);
        $processListArray = $databaseRepositoryPdo->showProcessList();

        $this->assertIsArray($processListArray);
    }
}
