<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Dto\InnodbTransaction;
use App\Dto\MysqlUser;
use App\Dto\ProcessList;
use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Repository\DatabaseOwnerRepository;
use App\Repository\SqlClientRepository;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\RepositoryPDO\MysqlAccountRepository;
use App\RepositoryPDO\ServerMonitorRepository;
use App\Security\SchemaVisibility;
use App\Security\SqlClientAccessResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[CoversClass(SchemaVisibility::class)]
final class SchemaVisibilityTest extends TestCase
{
    public function testAdminSeesEveryDatabase(): void
    {
        $databases = $this->visibility(admin: true, owned: [])->databasesWithStats(new AppUser(), new SqlClient(), $this->schema());

        $this->assertSame(['shop', 'other'], array_column($databases, 'db_name'));
    }

    public function testOwnerSeesOnlyOwnedDatabases(): void
    {
        $databases = $this->visibility(admin: false, owned: ['shop'])->databasesWithStats(new AppUser(), new SqlClient(), $this->schema());

        $this->assertSame(['shop'], array_column($databases, 'db_name'));
    }

    public function testAdminSeesEveryProcess(): void
    {
        $processes = $this->visibility(admin: true, owned: [])->processes(new AppUser(), new SqlClient(), $this->monitor());

        $this->assertCount(3, $processes);
    }

    public function testOwnerSeesOnlyProcessesOnOwnedDatabases(): void
    {
        $processes = $this->visibility(admin: false, owned: ['shop'])->processes(new AppUser(), new SqlClient(), $this->monitor());

        $this->assertSame([1], array_map(static fn (ProcessList $p): int => $p->id, $processes));
    }

    public function testAdminSeesEveryTransaction(): void
    {
        $transactions = $this->visibility(admin: true, owned: [])->transactions(new AppUser(), new SqlClient(), $this->monitor());

        $this->assertSame([1, 2, 9], array_map(static fn (InnodbTransaction $t): int => $t->threadId, $transactions));
    }

    public function testOwnerSeesOnlyTransactionsOfVisibleThreads(): void
    {
        $transactions = $this->visibility(admin: false, owned: ['shop'])->transactions(new AppUser(), new SqlClient(), $this->monitor());

        $this->assertSame([1], array_map(static fn (InnodbTransaction $t): int => $t->threadId, $transactions));
    }

    public function testAdminManagesAnyAccountWithoutQueryingTheServer(): void
    {
        $accounts = $this->createMock(MysqlAccountRepository::class);
        $accounts->expects($this->never())->method('hasGlobalPrivileges');

        $this->assertTrue($this->visibility(admin: true, owned: [])->canManageAccount(new AppUser(), new SqlClient(), $accounts, 'root', 'localhost'));
    }

    public function testOwnerManagesOnlyAccountsConfinedToItsDatabases(): void
    {
        $visibility = $this->visibility(admin: false, owned: ['shop']);
        $sqlClient = new SqlClient()->setUsername('panel');

        $this->assertTrue($visibility->canManageAccount(new AppUser(), $sqlClient, $this->accounts(), 'shop_rw', '%'));
        $this->assertFalse($visibility->canManageAccount(new AppUser(), $sqlClient, $this->accounts(), 'other_rw', '%'));
    }

    public function testAdminSeesEveryAccount(): void
    {
        $users = $this->visibility(admin: true, owned: [])->accounts(new AppUser(), new SqlClient(), $this->accounts(), '');

        $this->assertSame(['shop_rw', 'other_rw'], array_map(static fn (MysqlUser $u): string => $u->user, $users));
    }

    public function testOwnerDatabaseViewListsTheAccountsGrantedOnThatDatabase(): void
    {
        $users = $this->visibility(admin: false, owned: ['shop'])->accounts(new AppUser(), new SqlClient()->setUsername('panel'), $this->accounts(), 'shop');

        $this->assertSame(['shop_rw'], array_map(static fn (MysqlUser $u): string => $u->user, $users));
    }

    public function testOwnerServerViewListsTheAccountsItCanManage(): void
    {
        $users = $this->visibility(admin: false, owned: ['shop'])->accounts(new AppUser(), new SqlClient()->setUsername('panel'), $this->accounts(), '');

        $this->assertSame(['shop_rw'], array_map(static fn (MysqlUser $u): string => $u->user, $users));
    }

    public function testAdminSeesEveryGrantAndOwnerOnlyThoseOnOwnedDatabases(): void
    {
        $this->assertSame(['shop', 'other'], array_column($this->visibility(admin: true, owned: [])->grants(new AppUser(), new SqlClient(), $this->accounts(), 'mixed_rw', '%'), 'db'));
        $this->assertSame(['shop'], array_column($this->visibility(admin: false, owned: ['shop'])->grants(new AppUser(), new SqlClient(), $this->accounts(), 'mixed_rw', '%'), 'db'));
    }

    public function testAdminEditsAnyGrant(): void
    {
        $this->assertTrue($this->visibility(admin: true, owned: [])->canEditGrants(new AppUser(), new SqlClient(), $this->accounts(), 'root', '%', ['mysql']));
    }

    public function testOwnerEditsGrantsOnlyOnOwnedDatabasesOfAManageableAccount(): void
    {
        $visibility = $this->visibility(admin: false, owned: ['shop', 'shop_archive']);
        $sqlClient = new SqlClient()->setUsername('panel');

        $this->assertTrue($visibility->canEditGrants(new AppUser(), $sqlClient, $this->accounts(), 'shop_rw', '%', ['shop', 'shop_archive']));
        $this->assertFalse($visibility->canEditGrants(new AppUser(), $sqlClient, $this->accounts(), 'shop_rw', '%', ['shop', 'other']), 'a database not owned');
        $this->assertFalse($visibility->canEditGrants(new AppUser(), $sqlClient, $this->accounts(), 'other_rw', '%', ['shop']), 'an account not manageable');
    }

    private function schema(): DatabaseSchemaRepository
    {
        $schema = $this->createStub(DatabaseSchemaRepository::class);
        $schema->method('getDatabasesWithStats')->willReturn([
            ['db_name' => 'shop', 'table_count' => 3, 'size_bytes' => 1024],
            ['db_name' => 'other', 'table_count' => 1, 'size_bytes' => 512],
        ]);

        return $schema;
    }

    private function monitor(): ServerMonitorRepository
    {
        $row = static fn (int $id, ?string $db): ProcessList => new ProcessList($id, 'u', 'h', $db, 'Query', 0, state: null, info: null, executionEngine: 'InnoDB');
        $trx = static fn (int $threadId): InnodbTransaction => new InnodbTransaction($threadId, 'RUNNING', '2026-11-27 10:00:00', 0);
        $monitor = $this->createStub(ServerMonitorRepository::class);
        $monitor->method('showProcessList')->willReturn([$row(1, 'shop'), $row(2, 'other'), $row(3, null)]);
        $monitor->method('showInnodbTransactions')->willReturn([$trx(1), $trx(2), $trx(9)]);

        return $monitor;
    }

    private function accounts(): MysqlAccountRepository
    {
        $grant = static fn (string $db): array => ['db' => $db, 'privileges' => ['SELECT'], 'all_privileges' => false];
        $accounts = $this->createStub(MysqlAccountRepository::class);
        $accounts->method('hasGlobalPrivileges')->willReturn(false);
        $accounts->method('getUserGrantsByDb')->willReturnCallback(static fn (string $user): array => match ($user) {
            'shop_rw' => [$grant('shop')],
            'mixed_rw' => [$grant('shop'), $grant('other')],
            default => [$grant('other')],
        });
        $accounts->method('listUsers')->willReturnCallback(static fn (string $dbName): array => [
            new MysqlUser('shop_rw', '%', accountLocked: false, hasDbGrant: 'shop' === $dbName),
            new MysqlUser('other_rw', '%', accountLocked: false, hasDbGrant: 'other' === $dbName),
        ]);

        return $accounts;
    }

    /**
     * @param list<string> $owned
     */
    private function visibility(bool $admin, array $owned): SchemaVisibility
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($admin);
        $owners = $this->createStub(DatabaseOwnerRepository::class);
        $owners->method('findDbNamesOwnedOn')->willReturn($owned);

        return new SchemaVisibility(new SqlClientAccessResolver($this->createStub(SqlClientRepository::class), $checker), $owners);
    }
}
