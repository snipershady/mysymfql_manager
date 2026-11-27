<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\SqlClient;
use App\Repository\SqlClientRepository;
use App\Security\ManagedDatabase;
use App\Security\SqlClientAccessResolver;
use App\Security\Voter\DatabaseVoter;
use App\Security\Voter\SqlClientVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[CoversClass(SqlClientAccessResolver::class)]
#[UsesClass(ManagedDatabase::class)]
final class SqlClientAccessResolverTest extends TestCase
{
    private SqlClient $sqlClient;

    #[\Override]
    protected function setUp(): void
    {
        $this->sqlClient = new SqlClient()->setName('srv');
    }

    public function testServerIsReturnedWhenViewIsGranted(): void
    {
        $this->assertSame($this->sqlClient, $this->resolver(view: true, database: false)->server('srv'));
    }

    public function testServerIsNullWhenViewIsDenied(): void
    {
        $this->assertNull($this->resolver(view: false, database: true)->server('srv'));
    }

    public function testUnknownOrEmptyServerIsNull(): void
    {
        $resolver = $this->resolver(view: true, database: true);

        $this->assertNull($resolver->server('missing'));
        $this->assertNull($resolver->server(''));
    }

    public function testDatabaseRequiresBothServerAndDatabaseAccess(): void
    {
        $this->assertSame($this->sqlClient, $this->resolver(view: true, database: true)->database('srv', 'shop'));
        $this->assertNull($this->resolver(view: true, database: false)->database('srv', 'shop'));
        $this->assertNull($this->resolver(view: false, database: true)->database('srv', 'shop'));
    }

    public function testServerOrDatabaseNeedsDatabaseAccessOnlyWhenADatabaseIsGiven(): void
    {
        $serverOnly = $this->resolver(view: true, database: false);

        $this->assertSame($this->sqlClient, $serverOnly->serverOrDatabase('srv', dbName: null));
        $this->assertSame($this->sqlClient, $serverOnly->serverOrDatabase('srv', ''));
        $this->assertNull($serverOnly->serverOrDatabase('srv', 'shop'));
        $this->assertSame($this->sqlClient, $this->resolver(view: true, database: true)->serverOrDatabase('srv', 'shop'));
    }

    public function testIsAdminDelegatesToTheRoleCheck(): void
    {
        $this->assertTrue($this->resolver(view: false, database: false, admin: true)->isAdmin());
        $this->assertFalse($this->resolver(view: false, database: false)->isAdmin());
    }

    private function resolver(bool $view, bool $database, bool $admin = false): SqlClientAccessResolver
    {
        $repository = $this->createStub(SqlClientRepository::class);
        $repository->method('findOneByName')->willReturnCallback(
            fn (string $name): ?SqlClient => 'srv' === $name ? $this->sqlClient : null
        );

        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute, mixed $subject = null): bool => match ($attribute) {
                SqlClientVoter::VIEW => $view,
                DatabaseVoter::ACCESS => $database && $subject instanceof ManagedDatabase && 'shop' === $subject->dbName,
                'ROLE_ADMIN' => $admin,
                default => false,
            }
        );

        return new SqlClientAccessResolver($repository, $checker);
    }
}
