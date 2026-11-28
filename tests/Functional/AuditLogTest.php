<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Component\DatabaseClientConnection;
use App\Controller\AuditLogController;
use App\Entity\AppUser;
use App\Entity\AuditLogEntry;
use App\Entity\DatabaseOwner;
use App\Enum\AuditActionEnum;
use App\Enum\AuditOutcomeEnum;
use App\EventListener\AuditListener;
use App\Repository\AuditLogRepository;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Service\Audit\AuditTrail;
use App\Tests\Fixtures\TestCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Audit log end to end: the audited actions write one entry with who/what/outcome (never a password), refused
 * or safe requests write none, and only admins can read the log.
 */
#[CoversClass(AuditListener::class)]
#[CoversClass(AuditTrail::class)]
#[CoversClass(AuditLogRepository::class)]
#[CoversClass(AuditLogController::class)]
final class AuditLogTest extends FunctionalTestCase
{
    private string $server;

    private string $ownedDb;

    private string $foreignDb;

    private DatabaseSchemaRepository $remote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = self::env('TEST_SERVER_NAME');
        $this->ownedDb = self::env('TEST_DB_NAME_ONE');
        $this->foreignDb = self::env('TEST_DB_NAME_TWO');
        $this->remote = new DatabaseSchemaRepository($this->sqlClient($this->server));
        $this->remote->createDatabase($this->ownedDb);
    }

    protected function tearDown(): void
    {
        $this->remote->dropDatabase($this->ownedDb);
        DatabaseClientConnection::reset();
        parent::tearDown();
    }

    public function testSuccessfulQueryIsRecorded(): void
    {
        $owner = $this->loginAs(TestCatalog::OWNER);
        $this->schemaPost('/schema/query-execute', ['name' => $this->server, 'db_name' => $this->ownedDb, 'sql' => 'SELECT 1 AS one']);
        self::assertResponseIsSuccessful();

        $entry = $this->onlyEntry();
        $this->assertSame(AuditActionEnum::QUERY_EXECUTE, $entry->getAction());
        $this->assertSame(AuditOutcomeEnum::SUCCESS, $entry->getOutcome());
        $this->assertSame(TestCatalog::OWNER, $entry->getActorUsername());
        $this->assertSame($owner->getId(), $entry->getActorId());
        $this->assertSame('127.0.0.1', $entry->getIp());
        $this->assertSame([$this->server, $this->ownedDb], [$entry->getServerName(), $entry->getDbName()]);
        $this->assertSame(['sql' => 'SELECT 1 AS one'], $entry->getDetails());
        $this->assertNull($entry->getMessage());
    }

    public function testFailedQueryKeepsMysqlErrorAndHidesPasswords(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->schemaPost('/schema/query-execute', ['name' => $this->server, 'db_name' => $this->ownedDb, 'sql' => "SELECT * FROM missing_table WHERE password = 'S3cret!'"]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $entry = $this->onlyEntry();
        $this->assertSame(AuditOutcomeEnum::FAILURE, $entry->getOutcome());
        $this->assertStringStartsWith('ERROR 1146 (42S02)', (string) $entry->getMessage());
        $this->assertSame(['sql' => "SELECT * FROM missing_table WHERE password = '***'"], $entry->getDetails());
    }

    public function testDeniedOperationIsRecordedWithoutThePassword(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->schemaPost('/schema/db-user-create', [
            'name' => $this->server,
            'db_name' => $this->foreignDb,
            'username' => 'ut_audit_user',
            'password' => 'S3cret!',
            'user_host' => 'localhost',
            'privileges' => 'SELECT',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $entry = $this->onlyEntry();
        $this->assertSame(AuditActionEnum::MYSQL_USER_CREATE, $entry->getAction());
        $this->assertSame(AuditOutcomeEnum::DENIED, $entry->getOutcome());
        $this->assertSame($this->foreignDb, $entry->getDbName());
        $this->assertSame("'ut_audit_user'@'localhost'", $entry->getTarget());
        $this->assertStringNotContainsString('S3cret!', (string) json_encode([$entry->getDetails(), $entry->getMessage()]));
    }

    public function testRequestsRefusedBeforeTheActionAndPagesAreNotRecorded(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->client->request('GET', '/schema/query?name=' . $this->server . '&db_name=' . $this->ownedDb);
        self::assertResponseIsSuccessful();

        // No CSRF header: refused by SchemaCsrfListener, nothing attempted.
        $this->client->request('POST', '/schema/table-drop', ['name' => $this->server, 'db_name' => $this->ownedDb, 'table' => 't']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->assertSame([], $this->entries());
    }

    public function testPanelFormIsRecordedWithoutPasswords(): void
    {
        $this->loginAs(TestCatalog::ADMIN);
        $other = $this->user(TestCatalog::OTHER);

        $crawler = $this->client->request('GET', '/admin/appuser/' . $other->getId() . '/edit');
        $form = $crawler->selectButton('Salva modifiche')->form();
        $form['app_user_admin[plainPassword]'] = 'N3w-Passw0rd!';
        $this->client->submit($form);
        self::assertResponseRedirects();

        $entry = $this->onlyEntry();
        $this->assertSame(AuditActionEnum::APP_USER_UPDATE, $entry->getAction());
        $this->assertSame(AuditOutcomeEnum::SUCCESS, $entry->getOutcome());
        $this->assertSame(\sprintf('Utente #%d %s', (int) $other->getId(), TestCatalog::OTHER), $entry->getTarget());
        $details = (string) json_encode($entry->getDetails());
        $this->assertStringContainsString(TestCatalog::OTHER, $details);
        $this->assertStringNotContainsString('N3w-Passw0rd!', $details);
        $this->assertStringNotContainsString('_token', $details);
    }

    public function testPanelDeleteNeedsAValidTokenAndIsRecorded(): void
    {
        $this->loginAs(TestCatalog::ADMIN);
        $assignment = $this->entityManager()->getRepository(DatabaseOwner::class)->findOneBy(['dbName' => $this->foreignDb]);
        $this->assertInstanceOf(DatabaseOwner::class, $assignment);
        $id = (int) $assignment->getId();

        // Rejected by #[IsCsrfTokenValid] before the action (the firewall turns it into a redirect to the login).
        $this->client->request('POST', '/admin/database-owner/' . $id, ['_token' => 'forged']);
        $this->assertNotSame(Response::HTTP_SEE_OTHER, $this->client->getResponse()->getStatusCode());
        $this->assertSame([], $this->entries());
        $this->assertInstanceOf(DatabaseOwner::class, $this->entityManager()->find(DatabaseOwner::class, $id));

        $this->loginAs(TestCatalog::ADMIN);

        $crawler = $this->client->request('GET', '/admin/database-owner');
        $this->client->submit($crawler->filter(\sprintf('form[action="/admin/database-owner/%d"]', $id))->form());
        self::assertResponseRedirects('/admin/database-owner');

        $entry = $this->onlyEntry();
        $this->assertSame(AuditActionEnum::DATABASE_OWNER_DELETE, $entry->getAction());
        $this->assertSame(AuditOutcomeEnum::SUCCESS, $entry->getOutcome());
        $this->assertSame([TestCatalog::OTHER_SERVER, $this->foreignDb], [$entry->getServerName(), $entry->getDbName()]);
        $this->assertSame(\sprintf('Assegnazione #%d a %s', $id, TestCatalog::OTHER), $entry->getTarget());
    }

    public function testOnlyAdminsReadTheLogAndFiltersApply(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->schemaPost('/schema/query-execute', ['name' => $this->server, 'db_name' => $this->ownedDb, 'sql' => 'SELECT 1']);
        $this->schemaPost('/schema/table-drop', ['name' => $this->server, 'db_name' => $this->foreignDb, 'table' => 'orders']);

        $this->client->request('GET', '/admin/audit-log');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->loginAs(TestCatalog::ADMIN);
        $crawler = $this->client->request('GET', '/admin/audit-log');
        self::assertResponseIsSuccessful();
        $this->assertCount(2, $crawler->filter('#table-audit-log tbody tr'));

        $crawler = $this->client->request('GET', '/admin/audit-log?outcome=denied&action=&actor=' . TestCatalog::OWNER . '&from=2000-01-01');
        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('#table-audit-log tbody tr');
        $this->assertCount(1, $rows);
        $this->assertSame(AuditActionEnum::TABLE_DROP->value, $rows->attr('data-audit-action'));

        $this->client->request('GET', '/admin/audit-log?outcome=nope');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @return list<AuditLogEntry>
     */
    private function entries(): array
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        return $entityManager->getRepository(AuditLogEntry::class)->findBy([], ['id' => 'ASC']);
    }

    private function onlyEntry(): AuditLogEntry
    {
        $entries = $this->entries();
        $this->assertCount(1, $entries);

        return $entries[0];
    }

    private function user(string $username): AppUser
    {
        $user = $this->entityManager()->getRepository(AppUser::class)->findOneBy(['username' => $username]);
        $this->assertInstanceOf(AppUser::class, $user);

        return $user;
    }
}
