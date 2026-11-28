<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Component\DatabaseClientConnection;
use App\Controller\Schema\CockpitController;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\RepositoryPDO\MysqlAccountRepository;
use App\Service\Cockpit\CockpitService;
use App\Tests\Fixtures\TestCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cockpits end to end on the real server: who may see them, the data they return, and the sections that degrade
 * (with the GRANT to add) when the server account cannot read performance_schema/sys.
 */
#[CoversClass(CockpitController::class)]
#[CoversClass(CockpitService::class)]
final class CockpitTest extends FunctionalTestCase
{
    private string $server;

    private string $ownedDb;

    private DatabaseSchemaRepository $remote;

    private string $restrictedUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = self::env('TEST_SERVER_NAME');
        $this->ownedDb = self::env('TEST_DB_NAME_ONE');
        $this->restrictedUser = 'ut_cockpit_' . bin2hex(random_bytes(3));
        $this->remote = new DatabaseSchemaRepository($this->sqlClient($this->server));
        $this->remote->createDatabase($this->ownedDb);
        $this->remote->useDbName($this->ownedDb);
        $this->remote->runQuery('CREATE TABLE orders (id INT AUTO_INCREMENT PRIMARY KEY, note TEXT)');
        $this->remote->runQuery('CREATE TABLE audit_trail (at DATETIME)');
    }

    protected function tearDown(): void
    {
        new MysqlAccountRepository($this->sqlClient($this->server))->dropUser($this->restrictedUser, $this->accountHost());
        $this->remote->dropDatabase($this->ownedDb);
        $this->remote->dropDatabase(self::env('TEST_DB_NAME_TWO'));
        DatabaseClientConnection::reset();
        parent::tearDown();
    }

    public function testAdminServerCockpit(): void
    {
        $this->loginAs(TestCatalog::ADMIN);

        $this->client->request('GET', '/schema/server-cockpit', ['name' => $this->server]);
        self::assertResponseIsSuccessful();

        $data = $this->data('/schema/server-cockpit-data', ['name' => $this->server]);
        $this->assertTrue($data['is_valid']);
        $this->assertIsArray($data['gauges']);
        $this->assertGreaterThan(0, $data['gauges']['uptime']);
        $this->assertIsArray($data['counters']);
        $this->assertArrayHasKey('Questions', $data['counters']);
        $this->assertIsArray($data['config']);
        $this->assertArrayHasKey('max_connections', $data['config']);
        $this->assertIsArray($data['top_queries']);
        $this->assertTrue($data['top_queries']['available']);
        $this->assertContains($this->ownedDb, array_column($data['databases'], 'db_name'));
        $this->assertIsArray($data['severity']);
        $this->assertArrayHasKey('value', $data['severity']);
    }

    public function testPerconaStatisticsAreDetectedOrExplained(): void
    {
        $this->loginAs(TestCatalog::ADMIN);
        $server = $this->data('/schema/server-cockpit-data', ['name' => $this->server]);
        $schema = $this->data('/schema/schema-cockpit-data', ['name' => $this->server, 'db_name' => $this->ownedDb]);
        $this->assertIsArray($server['percona']);
        $this->assertIsArray($schema['percona']);
        $this->assertIsArray($server['config']);

        $isPercona = str_contains((string) ($server['config']['version_comment'] ?? ''), 'Percona');
        $this->assertSame($isPercona, $server['percona']['detected']);
        $this->assertSame($isPercona, $schema['percona']['detected']);
        if (!$isPercona) {
            $this->assertSame([], $server['percona']['sections'], 'stock MySQL: no Percona section');

            return;
        }

        $this->assertSame(['USER_STATISTICS', 'CLIENT_STATISTICS'], array_keys($server['percona']['sections']));
        $this->assertSame(['TABLE_STATISTICS', 'INDEX_STATISTICS'], array_keys($schema['percona']['sections']));
        if ('ON' !== $server['percona']['userstat']) {
            foreach ([...$server['percona']['sections'], ...$schema['percona']['sections']] as $section) {
                $this->assertFalse($section['available']);
                $this->assertSame('SET PERSIST userstat = ON', $section['grant']);
            }
            $this->assertIsArray($server['findings']);
            $this->assertStringContainsString('userstat disattivato', implode("\n", array_column($server['findings'], 'message')));
            $this->assertArrayHasKey('userstat', $server['config']);
        }
    }

    public function testServerCockpitIsAdminOnly(): void
    {
        $this->loginAs(TestCatalog::OWNER);

        $this->client->request('GET', '/schema/server-cockpit', ['name' => $this->server]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request('GET', '/schema/server-cockpit-data', ['name' => $this->server]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->loginAs(TestCatalog::ADMIN);
        $this->client->request('GET', '/schema/server-cockpit-data', ['name' => 'no_such_server']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOwnerSchemaCockpit(): void
    {
        $this->loginAs(TestCatalog::OWNER);

        $this->client->request('GET', '/schema/schema-cockpit', ['name' => $this->server, 'db_name' => $this->ownedDb]);
        self::assertResponseIsSuccessful();

        $data = $this->data('/schema/schema-cockpit-data', ['name' => $this->server, 'db_name' => $this->ownedDb]);
        $this->assertIsArray($data['summary']);
        $this->assertSame(2, $data['summary']['table_count']);
        $this->assertSame(['audit_trail'], $data['without_primary_key']);
        $this->assertSame(['orders'], array_column($data['auto_increment'], 'table_name'));
        $this->assertIsArray($data['findings']);
        $this->assertStringContainsString('senza primary key (audit_trail)', implode("\n", array_column($data['findings'], 'message')));
        $this->assertSame('warning', $data['severity']['value'] ?? null);
        foreach (['unused_indexes', 'redundant_indexes', 'table_io', 'top_queries', 'sessions'] as $section) {
            $this->assertIsArray($data[$section]);
            $this->assertTrue($data[$section]['available'], $section);
        }
    }

    public function testSchemaCockpitOfADatabaseNotOwnedIsNotFound(): void
    {
        $this->loginAs(TestCatalog::OTHER);

        $this->client->request('GET', '/schema/schema-cockpit', ['name' => $this->server, 'db_name' => $this->ownedDb]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->client->request('GET', '/schema/schema-cockpit-data', ['name' => $this->server, 'db_name' => $this->ownedDb]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testRestrictedAccountDegradesTheOptionalSections(): void
    {
        $otherDb = self::env('TEST_DB_NAME_TWO');
        $this->remote->createDatabase($otherDb);
        $host = $this->accountHost();
        $password = 'Cockpit-' . bin2hex(random_bytes(8));
        $accounts = new MysqlAccountRepository($this->sqlClient($this->server));
        $accounts->createUser($this->restrictedUser, $password, $host);
        $accounts->grantPrivileges($otherDb, $this->restrictedUser, 'ALL PRIVILEGES', $host);

        // server_other (owner test_other, database TEST_DB_NAME_TWO) with an account granted only on that database.
        $server = $this->sqlClient(TestCatalog::OTHER_SERVER)->setUsername($this->restrictedUser)->setPassword($password);
        $service = self::getContainer()->get(CockpitService::class);
        $this->assertInstanceOf(CockpitService::class, $service);

        $data = $service->schema($server, $otherDb);

        $this->assertSame(0, $data['summary']['table_count']);
        foreach (['unused_indexes', 'redundant_indexes', 'table_io', 'top_queries'] as $section) {
            $this->assertFalse($data[$section]['available'], $section);
            $this->assertMatchesRegularExpression('/^ERROR (1142|1044)/', (string) $data[$section]['error']);
            $this->assertStringStartsWith('GRANT SELECT ON ', (string) $data[$section]['grant']);
            $this->assertStringContainsString("'" . $this->restrictedUser . "'@", (string) $data[$section]['grant']);
        }
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function data(string $uri, array $query): array
    {
        $this->client->request('GET', $uri, $query);
        self::assertResponseIsSuccessful();

        /* @var array<string, mixed> */
        return $this->json();
    }

    private function accountHost(): string
    {
        return 'localhost' === self::env('TEST_SERVER_HOST') ? 'localhost' : '%';
    }
}
