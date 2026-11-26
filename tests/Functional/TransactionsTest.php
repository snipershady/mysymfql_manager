<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Component\DatabaseClientConnection;
use App\Controller\DatabaseSchemaRepositoryController;
use App\Dto\InnodbTransaction;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Tests\Fixtures\TestCatalog;
use App\Tests\Support\OpenTransaction;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * "Transaction" CTA: INNODB_TRX listing with the process-list visibility rules.
 * Two real transactions are opened: one on the owner's database, one on a database it does not own.
 */
#[CoversClass(DatabaseSchemaRepositoryController::class)]
final class TransactionsTest extends FunctionalTestCase
{
    private DatabaseSchemaRepository $remote;

    private OpenTransaction $ownedTrx;

    private OpenTransaction $foreignTrx;

    protected function setUp(): void
    {
        parent::setUp();
        $sqlClient = $this->sqlClient(self::env('TEST_SERVER_NAME'));
        $this->remote = new DatabaseSchemaRepository($sqlClient);
        $this->remote->createDatabase(self::env('TEST_DB_NAME_ONE'));
        $this->remote->createDatabase(self::env('TEST_DB_NAME_TWO'));

        $this->ownedTrx = new OpenTransaction($sqlClient, self::env('TEST_DB_NAME_ONE'));
        $this->foreignTrx = new OpenTransaction($sqlClient, self::env('TEST_DB_NAME_TWO'));
        // INNODB_TRX is a cache refreshed at most every 0.1 s: wait until both transactions are in it.
        $threads = [$this->ownedTrx->threadId, $this->foreignTrx->threadId];
        $this->assertTrue(OpenTransaction::eventually(fn (): bool => [] === array_diff(
            $threads,
            array_map(static fn (InnodbTransaction $t): int => $t->threadId, $this->remote->showInnodbTransactions())
        )));
    }

    protected function tearDown(): void
    {
        $this->ownedTrx->close();
        $this->foreignTrx->close();
        $this->remote->dropDatabase(self::env('TEST_DB_NAME_ONE'));
        $this->remote->dropDatabase(self::env('TEST_DB_NAME_TWO'));
        DatabaseClientConnection::reset();
        parent::tearDown();
    }

    public function testCtaIsShownNextToProcessList(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->client->request('GET', '/schema/databases');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#btn-open-transactions', 'Transaction');
    }

    public function testOwnerSeesOnlyTransactionsOnItsDatabases(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $threads = $this->listedThreads();

        $this->assertContains($this->ownedTrx->threadId, $threads);
        $this->assertNotContains($this->foreignTrx->threadId, $threads);
    }

    public function testAdminSeesEveryTransaction(): void
    {
        $this->loginAs(TestCatalog::ADMIN);
        $threads = $this->listedThreads();

        $this->assertContains($this->ownedTrx->threadId, $threads);
        $this->assertContains($this->foreignTrx->threadId, $threads);
    }

    public function testRowsExposeTheRequestedColumns(): void
    {
        $this->loginAs(TestCatalog::ADMIN);
        $this->client->request('GET', '/schema/transactions-get-data', ['name' => self::env('TEST_SERVER_NAME')]);

        $row = array_find($this->json()['data'], fn (array $r): bool => $r['threadId'] === $this->ownedTrx->threadId);
        $this->assertIsArray($row);
        $this->assertSame(['threadId', 'state', 'started', 'rowsLocked'], array_keys($row));
        $this->assertSame('RUNNING', $row['state']);
    }

    public function testNonOwnerOfTheServerGets404(): void
    {
        $this->loginAs(TestCatalog::OTHER);

        $this->client->request('GET', '/schema/transactions-get-data', ['name' => self::env('TEST_SERVER_NAME')]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/schema/transactions', ['name' => self::env('TEST_SERVER_NAME')]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPageRenders(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->client->request('GET', '/schema/transactions', ['name' => self::env('TEST_SERVER_NAME')]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table#table-transactions');
    }

    /**
     * @return list<int>
     */
    private function listedThreads(): array
    {
        $this->client->request('GET', '/schema/transactions-get-data', ['name' => self::env('TEST_SERVER_NAME')]);
        self::assertResponseIsSuccessful();

        return array_column($this->json()['data'], 'threadId');
    }
}
