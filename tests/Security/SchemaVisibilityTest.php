<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Dto\ProcessList;
use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Repository\DatabaseOwnerRepository;
use App\Repository\SqlClientRepository;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Security\SchemaVisibility;
use App\Security\SqlClientAccessResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[CoversClass(SchemaVisibility::class)]
final class SchemaVisibilityTest extends TestCase
{
    public function testAdminSeesEveryProcess(): void
    {
        $processes = $this->visibility(admin: true, owned: [])->processes(new AppUser(), new SqlClient(), $this->repo());

        $this->assertCount(3, $processes);
    }

    public function testOwnerSeesOnlyProcessesOnOwnedDatabases(): void
    {
        $processes = $this->visibility(admin: false, owned: ['shop'])->processes(new AppUser(), new SqlClient(), $this->repo());

        $this->assertSame([1], array_map(static fn (ProcessList $p): int => $p->id, $processes));
    }

    public function testAdminManagesAnyAccountWithoutQueryingTheServer(): void
    {
        $repo = $this->createMock(DatabaseSchemaRepository::class);
        $repo->expects($this->never())->method('hasGlobalPrivileges');

        $this->assertTrue($this->visibility(admin: true, owned: [])->canManageAccount(new AppUser(), new SqlClient(), $repo, 'root', 'localhost'));
    }

    public function testOwnerManagesOnlyAccountsConfinedToItsDatabases(): void
    {
        $repo = $this->createStub(DatabaseSchemaRepository::class);
        $repo->method('hasGlobalPrivileges')->willReturn(false);
        $repo->method('getUserGrantsByDb')->willReturnCallback(static fn (string $user): array => 'shop_rw' === $user
            ? [['db' => 'shop', 'privileges' => ['SELECT'], 'all_privileges' => false]]
            : [['db' => 'other', 'privileges' => ['SELECT'], 'all_privileges' => false]]);
        $visibility = $this->visibility(admin: false, owned: ['shop']);
        $sqlClient = new SqlClient()->setUsername('panel');

        $this->assertTrue($visibility->canManageAccount(new AppUser(), $sqlClient, $repo, 'shop_rw', '%'));
        $this->assertFalse($visibility->canManageAccount(new AppUser(), $sqlClient, $repo, 'other_rw', '%'));
    }

    private function repo(): DatabaseSchemaRepository
    {
        $row = static fn (int $id, ?string $db): ProcessList => new ProcessList($id, 'u', 'h', $db, 'Query', 0, state: null, info: null, executionEngine: 'InnoDB');
        $repo = $this->createStub(DatabaseSchemaRepository::class);
        $repo->method('showProcessList')->willReturn([$row(1, 'shop'), $row(2, 'other'), $row(3, null)]);

        return $repo;
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
