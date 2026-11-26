<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\SqlClientController;
use App\Entity\SqlClient;
use App\Security\Voter\SqlClientVoter;
use App\Tests\Fixtures\TestCatalog;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Backlog point 2: server management is admin-only, viewing needs ownership.
 */
#[CoversClass(SqlClientController::class)]
#[CoversClass(SqlClientVoter::class)]
final class SqlClientAccessTest extends FunctionalTestCase
{
    public function testIndexListsOnlyOwnedServers(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->client->request('GET', '/sqlclient');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', self::env('TEST_SERVER_NAME'));
        self::assertSelectorTextNotContains('body', TestCatalog::OTHER_SERVER);
        // No management actions for a non-admin.
        self::assertSelectorNotExists('a[href="/sqlclient/new"]');
    }

    public function testOwnerCanViewButNotManageItsServer(): void
    {
        $id = $this->sqlClient(self::env('TEST_SERVER_NAME'))->getId();
        $this->loginAs(TestCatalog::OWNER);

        $this->client->request('GET', '/sqlclient/' . $id);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/sqlclient/' . $id . '/edit"]');

        $this->client->request('GET', '/sqlclient/' . $id . '/edit');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/sqlclient/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testNonOwnerCannotViewAServer(): void
    {
        $id = $this->sqlClient(self::env('TEST_SERVER_NAME'))->getId();
        $this->loginAs(TestCatalog::OTHER);

        $this->client->request('GET', '/sqlclient/' . $id);

        self::assertResponseStatusCodeSame(403);
    }

    public function testNonAdminCannotDeleteAServerEvenWithAValidToken(): void
    {
        $sqlClient = $this->sqlClient(self::env('TEST_SERVER_NAME'));
        $id = $sqlClient->getId();
        $this->loginAs(TestCatalog::OWNER);

        $this->client->request('POST', '/sqlclient/' . $id, ['_token' => 'irrelevant']);

        self::assertResponseStatusCodeSame(403);
        $this->entityManager()->clear();
        self::assertInstanceOf(SqlClient::class, $this->entityManager()->find(SqlClient::class, $id));
    }

    public function testAdminCanManageAnyServer(): void
    {
        $id = $this->sqlClient(TestCatalog::OTHER_SERVER)->getId();
        $this->loginAs(TestCatalog::ADMIN);

        $this->client->request('GET', '/sqlclient/' . $id . '/edit');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/sqlclient/new');
        self::assertResponseIsSuccessful();
    }
}
