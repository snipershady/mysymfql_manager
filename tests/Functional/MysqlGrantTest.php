<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Component\DatabaseClientConnection;
use App\Controller\Schema\MysqlUserController;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\RepositoryPDO\MysqlAccountRepository;
use App\Service\MysqlAccountManager;
use App\Tests\Fixtures\TestCatalog;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Grant changes from the HTTP endpoint (the JSON the grant modal sends), on the real managed server:
 * replacement and revocation by the owner, and a denied request that leaves the server untouched.
 * test_owner owns TEST_DB_NAME_ONE on the server; TEST_DB_NAME_TWO is not its own there.
 */
#[CoversClass(MysqlUserController::class)]
#[CoversClass(MysqlAccountManager::class)]
final class MysqlGrantTest extends FunctionalTestCase
{
    private DatabaseSchemaRepository $remote;

    private MysqlAccountRepository $accounts;

    private string $server;

    private string $ownedDb;

    private string $foreignDb;

    private string $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = self::env('TEST_SERVER_NAME');
        $this->ownedDb = self::env('TEST_DB_NAME_ONE');
        $this->foreignDb = self::env('TEST_DB_NAME_TWO');
        $this->account = 'ut_grant_' . bin2hex(random_bytes(3));
        $sqlClient = $this->sqlClient($this->server);
        $this->remote = new DatabaseSchemaRepository($sqlClient);
        $this->accounts = new MysqlAccountRepository($sqlClient);
        $this->remote->createDatabase($this->ownedDb);
        $this->remote->createDatabase($this->foreignDb);

        $this->accounts->createUser($this->account, 'Str0ng-' . bin2hex(random_bytes(6)), '%');
        $this->accounts->grantPrivileges($this->ownedDb, $this->account, 'ALL PRIVILEGES', '%');
    }

    protected function tearDown(): void
    {
        $this->accounts->dropUser($this->account, '%');
        $this->remote->dropDatabase($this->ownedDb);
        $this->remote->dropDatabase($this->foreignDb);
        DatabaseClientConnection::reset();
        parent::tearDown();
    }

    public function testOwnerReplacesThenRevokesTheGrantsOnItsDatabase(): void
    {
        $this->loginAs(TestCatalog::OWNER);

        $this->saveGrants([['db' => $this->ownedDb, 'privileges' => 'SELECT,UPDATE']], []);
        self::assertResponseIsSuccessful();
        $this->assertSame(['is_valid' => true, 'message' => 'Permessi aggiornati.'], $this->json());
        $this->assertSame(
            [['db' => $this->ownedDb, 'privileges' => ['SELECT', 'UPDATE'], 'all_privileges' => false]],
            $this->accounts->getUserGrantsByDb($this->account, '%'),
        );

        $this->saveGrants([], [$this->ownedDb]);
        self::assertResponseIsSuccessful();
        $this->assertSame([], $this->accounts->getUserGrantsByDb($this->account, '%'));
    }

    public function testOwnerCannotGrantADatabaseItDoesNotOwnAndNothingChanges(): void
    {
        $this->loginAs(TestCatalog::OWNER);

        $this->saveGrants([
            ['db' => $this->ownedDb, 'privileges' => 'SELECT'],
            ['db' => $this->foreignDb, 'privileges' => 'SELECT'],
        ], []);

        self::assertResponseStatusCodeSame(404);
        $this->assertFalse($this->json()['is_valid']);
        $this->assertSame(
            [['db' => $this->ownedDb, 'privileges' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'REFERENCES', 'INDEX', 'ALTER', 'CREATE VIEW', 'SHOW VIEW', 'CREATE ROUTINE', 'ALTER ROUTINE', 'EXECUTE', 'TRIGGER', 'EVENT'], 'all_privileges' => true]],
            $this->accounts->getUserGrantsByDb($this->account, '%'),
        );
    }

    public function testGrantModalDataShowOnlyOwnedDatabasesToTheOwner(): void
    {
        $this->accounts->grantPrivileges($this->foreignDb, $this->account, 'SELECT', '%');
        $this->loginAs(TestCatalog::OWNER);

        $this->client->request('GET', '/schema/db-user-grants-data', ['name' => $this->server, 'username' => $this->account, 'user_host' => '%']);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertSame([$this->ownedDb], array_column($data['databases'], 'db_name'));
        $this->assertSame([$this->ownedDb], array_column($data['grants'], 'db'));
    }

    /**
     * @param list<array{db: string, privileges: string}> $grants
     * @param list<string>                                $revokedDbs
     */
    private function saveGrants(array $grants, array $revokedDbs): void
    {
        $token = $this->schemaCsrfToken();
        $this->client->jsonRequest('POST', '/schema/db-user-grant-save', [
            'name' => $this->server, 'username' => $this->account, 'user_host' => '%',
            'grants' => $grants, 'revoked_dbs' => $revokedDbs,
        ], ['HTTP_X_CSRF_TOKEN' => $token]);
    }
}
