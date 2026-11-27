<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Component\DatabaseClientConnection;
use App\EventListener\JsonValidationErrorListener;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Tests\Fixtures\TestCatalog;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Request DTOs end to end: typed mapping (form, query string, JSON body), validation answered as
 * {is_valid: false, message} with 422, and no effect on the managed server for invalid input.
 */
#[CoversClass(JsonValidationErrorListener::class)]
final class InputValidationTest extends FunctionalTestCase
{
    private DatabaseSchemaRepository $remote;

    private string $server;

    private string $db;

    private string $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = self::env('TEST_SERVER_NAME');
        $this->db = self::env('TEST_DB_NAME_ONE');
        $this->account = 'ut_grant_' . bin2hex(random_bytes(3));
        $this->remote = new DatabaseSchemaRepository($this->sqlClient($this->server));
        $this->remote->createDatabase($this->db);
    }

    protected function tearDown(): void
    {
        $this->remote->dropUser($this->account);
        $this->remote->dropDatabase($this->db);
        $this->remote->dropDatabase('unit_testing_bad_charset');
        DatabaseClientConnection::reset();
        parent::tearDown();
    }

    public function testBlankSqlIsAnsweredInTheFrontEndFormat(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->schemaPost('/schema/query-execute', ['name' => $this->server, 'db_name' => $this->db, 'sql' => '   ']);

        self::assertResponseStatusCodeSame(422);
        $json = $this->json();
        $this->assertFalse($json['is_valid']);
        $this->assertSame('Query: La query non può essere vuota.', $json['message']);
        $this->assertSame('sql', $json['errors'][0]['field']);
    }

    public function testWrongTypeIsAValidationErrorNotAnException(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->schemaPost('/schema/kill-process', ['name' => $this->server, 'pid' => 'abc']);

        self::assertResponseStatusCodeSame(422);
        $this->assertStringStartsWith('PID: ', $this->json()['message']);
    }

    public function testUnknownCharsetIsRejectedAndNothingIsCreated(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->schemaPost('/schema/create-database', [
            'name' => $this->server, 'db_name' => 'unit_testing_bad_charset', 'charset' => 'ebcdic',
            'collation' => 'utf8mb4_0900_ai_ci', 'username' => $this->account, 'password' => 'Str0ng-pw',
            'user_host' => '%', 'privileges' => 'SELECT',
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertStringStartsWith('Charset: ', $this->json()['message']);
        $this->assertNotContains('unit_testing_bad_charset', $this->remote->showDatabases());
        $this->assertSame([], $this->remote->getHostByUser($this->account));
    }

    public function testInvalidTableNameIsRejectedInsteadOfBeingRewritten(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->schemaPost('/schema/table-drop', ['name' => $this->server, 'db_name' => $this->db, 'table' => 'jujutsu/../x']);

        self::assertResponseStatusCodeSame(422);
        $this->assertStringStartsWith('Tabella: ', $this->json()['message']);
    }

    public function testInvalidQueryStringOnAnHtmlPageKeepsTheStandardErrorPage(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->client->request('GET', '/schema/tables', ['name' => $this->server, 'db_name' => 'a.b']);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
    }

    public function testGrantsAreSavedFromATypedJsonBody(): void
    {
        $this->remote->createUser($this->account, 'Str0ng-pw', '%');
        $this->remote->grantPrivileges($this->db, $this->account, 'ALL PRIVILEGES', '%');
        $this->loginAs(TestCatalog::OWNER);

        $this->postJson('/schema/db-user-grant-save', [
            'name' => $this->server, 'username' => $this->account, 'user_host' => '%',
            'grants' => [['db' => $this->db, 'privileges' => 'SELECT, INSERT']], 'revoked_dbs' => [],
        ]);

        self::assertResponseIsSuccessful();
        $this->assertTrue($this->json()['is_valid']);
        $grants = $this->remote->getUserGrantsByDb($this->account, '%');
        $this->assertSame([['db' => $this->db, 'privileges' => ['SELECT', 'INSERT'], 'all_privileges' => false]], $grants);
    }

    public function testInvalidPrivilegeInJsonBodyChangesNothing(): void
    {
        $this->remote->createUser($this->account, 'Str0ng-pw', '%');
        $this->remote->grantPrivileges($this->db, $this->account, 'SELECT', '%');
        $this->loginAs(TestCatalog::OWNER);

        $this->postJson('/schema/db-user-grant-save', [
            'name' => $this->server, 'username' => $this->account, 'user_host' => '%',
            'grants' => [['db' => $this->db, 'privileges' => 'SELECT, SUPER']], 'revoked_dbs' => [$this->db],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertSame('Grant: Privilegio non supportato: "SUPER".', $this->json()['message']);
        $this->assertSame(['SELECT'], $this->remote->getUserGrantsByDb($this->account, '%')[0]['privileges']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(string $uri, array $body): void
    {
        $token = $this->schemaCsrfToken();
        $this->client->request('POST', $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
