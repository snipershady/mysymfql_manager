<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Component\DatabaseClientConnection;
use App\Controller\Schema\DatabaseController;
use App\Controller\Schema\MonitorController;
use App\Controller\Schema\TableController;
use App\Entity\DatabaseOwner;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Security\SqlClientAccessResolver;
use App\Security\Voter\DatabaseVoter;
use App\Service\DatabaseProvisioner;
use App\Tests\Fixtures\TestCatalog;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Backlog points 1 and 11 through HTTP, with the three panel roles of {@see TestCatalog}.
 * Denied requests must have no effect on the managed server; remote schemas are created in
 * setUp() and dropped in tearDown() (the catalog is rolled back by dama/doctrine-test-bundle).
 */
#[CoversClass(DatabaseController::class)]
#[CoversClass(TableController::class)]
#[CoversClass(MonitorController::class)]
#[CoversClass(SqlClientAccessResolver::class)]
#[CoversClass(DatabaseVoter::class)]
#[CoversClass(DatabaseProvisioner::class)]
final class SchemaAuthorizationTest extends FunctionalTestCase
{
    private DatabaseSchemaRepository $remote;

    private string $server;

    private string $ownedDb;

    private string $foreignDb;

    private string $newDb;

    private string $newUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = self::env('TEST_SERVER_NAME');
        $this->ownedDb = self::env('TEST_DB_NAME_ONE');
        $this->foreignDb = self::env('TEST_DB_NAME_TWO');
        $suffix = bin2hex(random_bytes(4));
        $this->newDb = 'unit_testing_http_' . $suffix;
        $this->newUser = 'ut_http_' . $suffix;
        $this->remote = new DatabaseSchemaRepository($this->sqlClient($this->server));
        $this->remote->createDatabase($this->ownedDb);
        $this->remote->createDatabase($this->foreignDb);
    }

    protected function tearDown(): void
    {
        $this->remote->dropUser($this->newUser);
        foreach ([$this->ownedDb, $this->foreignDb, $this->newDb] as $db) {
            $this->remote->dropDatabase($db);
        }
        DatabaseClientConnection::reset();
        parent::tearDown();
    }

    public function testOwnerReachesItsDatabase(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->client->request('GET', '/schema/show-tables-get-data', ['name' => $this->server, 'db_name' => $this->ownedDb]);

        self::assertResponseIsSuccessful();
        $this->assertArrayHasKey('data', $this->json());
    }

    public function testServerOwnerCannotReachADatabaseItDoesNotOwn(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->client->request('GET', '/schema/show-tables-get-data', ['name' => $this->server, 'db_name' => $this->foreignDb]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testUserCannotReachAServerItDoesNotOwn(): void
    {
        $this->loginAs(TestCatalog::OTHER);
        $this->client->request('GET', '/schema/show-tables-get-data', ['name' => $this->server, 'db_name' => $this->ownedDb]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testDatabaseListIsFilteredByOwnership(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->client->request('GET', '/schema/show-databases-get-data', ['name' => $this->server]);

        self::assertResponseIsSuccessful();
        $names = array_column($this->json()['data'], 'db_name');
        $this->assertSame([$this->ownedDb], $names);
    }

    public function testAdminReachesEveryDatabase(): void
    {
        $this->loginAs(TestCatalog::ADMIN);
        $this->client->request('GET', '/schema/show-tables-get-data', ['name' => $this->server, 'db_name' => $this->foreignDb]);

        self::assertResponseIsSuccessful();
    }

    public function testDeniedDropHasNoRemoteEffect(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->schemaPost('/schema/drop-database', ['name' => $this->server, 'db_name' => $this->foreignDb]);

        self::assertResponseStatusCodeSame(404);
        $this->assertContains($this->foreignDb, $this->remote->showDatabases());
    }

    public function testOwnerDropRemovesTheDatabaseAndItsOwnerships(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->schemaPost('/schema/drop-database', ['name' => $this->server, 'db_name' => $this->ownedDb]);

        self::assertResponseIsSuccessful();
        $this->assertTrue($this->json()['is_valid']);
        $this->assertNotContains($this->ownedDb, $this->remote->showDatabases());
        $this->assertSame(0, $this->entityManager()->getRepository(DatabaseOwner::class)->count(['dbName' => $this->ownedDb]));
    }

    public function testEngineStatusIsAdminOnly(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->client->request('GET', '/schema/show-engine-status-get-data', ['name' => $this->server]);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs(TestCatalog::ADMIN);
        $this->client->request('GET', '/schema/show-engine-status-get-data', ['name' => $this->server]);
        self::assertResponseIsSuccessful();
    }

    public function testOwnerCannotKillAThreadOutsideItsDatabases(): void
    {
        // The panel's own connection: no current schema, so it is not visible to a non-admin.
        $pdo = DatabaseClientConnection::getInstance($this->sqlClient($this->server));
        $pdo->exec('USE mysql');

        $threadId = (int) $pdo->query('SELECT CONNECTION_ID()')?->fetchColumn();

        $this->loginAs(TestCatalog::OWNER);
        $this->schemaPost('/schema/kill-process', ['name' => $this->server, 'pid' => $threadId]);

        self::assertResponseStatusCodeSame(404);
        $this->assertSame($threadId, (int) $pdo->query('SELECT CONNECTION_ID()')?->fetchColumn());
    }

    public function testOwnerCreatesADatabaseAndBecomesItsOwner(): void
    {
        $owner = $this->loginAs(TestCatalog::OWNER);
        $this->schemaPost('/schema/create-database', $this->createPayload($this->newDb));

        self::assertResponseIsSuccessful();
        $this->assertTrue($this->json()['is_valid']);
        $this->assertContains($this->newDb, $this->remote->showDatabases());
        $this->assertSame(1, $this->entityManager()->getRepository(DatabaseOwner::class)->count(['dbName' => $this->newDb, 'owner' => $owner]));
    }

    public function testCreatingAnExistingDatabaseIsAConflictAndDoesNotGrantOwnership(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->schemaPost('/schema/create-database', $this->createPayload($this->foreignDb));

        self::assertResponseStatusCodeSame(409);
        $this->assertSame([], $this->remote->getHostByUser($this->newUser));
        $this->assertSame(0, $this->entityManager()->getRepository(DatabaseOwner::class)->count(['dbName' => $this->foreignDb, 'owner' => $this->loginAs(TestCatalog::OWNER)]));
    }

    /**
     * @return array<string, string>
     */
    private function createPayload(string $dbName): array
    {
        return [
            'name' => $this->server,
            'db_name' => $dbName,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
            'username' => $this->newUser,
            'password' => 'Str0ng-' . bin2hex(random_bytes(6)),
            'user_host' => '%',
            'privileges' => 'SELECT,INSERT',
        ];
    }
}
