<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\MysqlAccountPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MysqlAccountPolicy::class)]
final class MysqlAccountPolicyTest extends TestCase
{
    private const array OWNED = ['shop', 'blog'];

    public function testAccountConfinedToOwnedDatabasesIsManageable(): void
    {
        $this->assertTrue(MysqlAccountPolicy::isManageableByOwner('panel', 'shop_rw', hasGlobalPrivileges: false, grantedDbs: ['shop', 'blog'], ownedDbs: self::OWNED));
    }

    /**
     * @return iterable<string, array{string, string, bool, list<string>}>
     */
    public static function deniedCases(): iterable
    {
        yield 'empty username' => ['panel', '', false, ['shop']];
        yield 'panel own account' => ['panel', 'panel', false, ['shop']];
        yield 'root' => ['panel', 'root', false, ['shop']];
        yield 'mysql.sys' => ['panel', 'mysql.sys', false, ['shop']];
        yield 'mysql.session' => ['panel', 'mysql.session', false, ['shop']];
        yield 'mysql.infoschema' => ['panel', 'mysql.infoschema', false, ['shop']];
        yield 'global privileges' => ['panel', 'shop_rw', true, ['shop']];
        yield 'no database grant' => ['panel', 'shop_rw', false, []];
        yield 'grant on a foreign database' => ['panel', 'shop_rw', false, ['shop', 'other_tenant']];
        yield 'wildcard grant' => ['panel', 'shop_rw', false, ['shop%']];
        yield 'escaped wildcard grant' => ['panel', 'shop_rw', false, ['sho\_p']];
    }

    /**
     * @param list<string> $grantedDbs
     */
    #[DataProvider('deniedCases')]
    public function testAccountIsNotManageable(string $panelAccount, string $username, bool $global, array $grantedDbs): void
    {
        $this->assertFalse(MysqlAccountPolicy::isManageableByOwner($panelAccount, $username, $global, $grantedDbs, self::OWNED));
    }
}
