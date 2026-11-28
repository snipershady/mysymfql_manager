<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Component\DatabaseClientConnection;
use App\Controller\Schema\QueryController;
use App\Repository\AuditLogRepository;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Tests\Fixtures\TestCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Query console history: the user's own statements on the database, newest first, one item per statement, built on
 * the audit log (passwords redacted).
 */
#[CoversClass(QueryController::class)]
#[CoversClass(AuditLogRepository::class)]
final class QueryHistoryTest extends FunctionalTestCase
{
    private string $server;

    private string $ownedDb;

    private DatabaseSchemaRepository $remote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = self::env('TEST_SERVER_NAME');
        $this->ownedDb = self::env('TEST_DB_NAME_ONE');
        $this->remote = new DatabaseSchemaRepository($this->sqlClient($this->server));
        $this->remote->createDatabase($this->ownedDb);
    }

    protected function tearDown(): void
    {
        $this->remote->dropDatabase($this->ownedDb);
        DatabaseClientConnection::reset();
        parent::tearDown();
    }

    public function testOwnStatementsNewestFirstWithoutDuplicates(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        foreach (['SELECT 1', 'SELECT * FROM missing_table', 'SELECT 1', "SET @password = 'S3cret!'"] as $sql) {
            $this->schemaPost('/schema/query-execute', ['name' => $this->server, 'db_name' => $this->ownedDb, 'sql' => $sql]);
        }

        $history = $this->history();

        $this->assertSame(["SET @password = '***'", 'SELECT 1', 'SELECT * FROM missing_table'], array_column($history, 'sql'));
        $this->assertSame([true, true, false], array_column($history, 'ok'));
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, (string) $history[0]['occurred_at']));
    }

    public function testOtherUsersStatementsAreNotShown(): void
    {
        $this->loginAs(TestCatalog::ADMIN);
        $this->schemaPost('/schema/query-execute', ['name' => $this->server, 'db_name' => $this->ownedDb, 'sql' => 'SELECT 42']);

        $this->loginAs(TestCatalog::OWNER);

        $this->assertSame([], $this->history());
    }

    public function testDatabaseNotOwnedIsNotFound(): void
    {
        $this->loginAs(TestCatalog::OTHER);
        $this->client->request('GET', '/schema/query-history', ['name' => $this->server, 'db_name' => $this->ownedDb]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(): array
    {
        $this->client->request('GET', '/schema/query-history', ['name' => $this->server, 'db_name' => $this->ownedDb]);
        self::assertResponseIsSuccessful();
        $history = $this->json()['history'] ?? null;
        $this->assertIsArray($history);

        /* @var list<array<string, mixed>> $history */
        return $history;
    }
}
