<?php

declare(strict_types=1);

namespace App\Tests\Security\Grants;

use App\Security\Grants\GrantStatementParser;
use App\Security\Grants\ParsedGrants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GrantStatementParser::class)]
#[CoversClass(ParsedGrants::class)]
final class GrantStatementParserTest extends TestCase
{
    public function testRestrictedAccount(): void
    {
        $parsed = new GrantStatementParser()->parse([
            'GRANT USAGE ON *.* TO `shop_panel`@`%`',
            'GRANT ALL PRIVILEGES ON `shop`.* TO `shop_panel`@`%`',
            'GRANT SELECT, INSERT ON `shop\_archive`.* TO `shop_panel`@`%`',
            'GRANT SELECT (`id`, `name`), UPDATE (`name`) ON `crm`.`customers` TO `shop_panel`@`%`',
            'GRANT EXECUTE ON PROCEDURE `crm`.`refresh` TO `shop_panel`@`%`',
        ]);

        $this->assertSame([], $parsed->globalPrivileges);
        $this->assertSame([
            'shop' => ['ALL PRIVILEGES'],
            'shop\_archive' => ['SELECT', 'INSERT'],
            'crm' => ['SELECT', 'UPDATE', 'EXECUTE'],
        ], $parsed->databases);
        $this->assertFalse($parsed->grantOption);
        $this->assertSame([], $parsed->roles);
        $this->assertSame([], $parsed->unparsed);
    }

    public function testGlobalDynamicRolesProxyAndGrantOption(): void
    {
        $parsed = new GrantStatementParser()->parse([
            'GRANT SELECT, PROCESS ON *.* TO `ops`@`localhost` WITH GRANT OPTION',
            'GRANT BACKUP_ADMIN,SYSTEM_VARIABLES_ADMIN ON *.* TO `ops`@`localhost`',
            'GRANT PROXY ON ``@`` TO `ops`@`localhost`',
            'GRANT `reporting`@`%`,`audit` TO `ops`@`localhost`',
            'REVOKE INSERT ON `mysql`.* FROM `ops`@`localhost`',
            'GRANT SOMETHING NEW',
            '',
        ]);

        $this->assertSame(['SELECT', 'PROCESS', 'BACKUP_ADMIN', 'SYSTEM_VARIABLES_ADMIN'], $parsed->globalPrivileges);
        $this->assertTrue($parsed->grantOption);
        $this->assertTrue($parsed->proxy);
        $this->assertSame(['`reporting`@`%`', '`audit`'], $parsed->roles);
        $this->assertSame(['GRANT SOMETHING NEW'], $parsed->unparsed);
    }

    public function testQuotedNamesAndPatterns(): void
    {
        $parsed = new GrantStatementParser()->parse(['GRANT SELECT ON `we``ird`.* TO `u`@`%`', 'GRANT SELECT ON `app_%`.* TO `u`@`%`']);

        $this->assertSame(['we`ird', 'app_%'], array_keys($parsed->databases));
        $this->assertTrue(ParsedGrants::isWildcard('app_%'));
        $this->assertFalse(ParsedGrants::isWildcard('app\%'));
        $this->assertFalse(ParsedGrants::isWildcard('shop_2026'));
        $this->assertSame('shop_archive', ParsedGrants::literalName('shop\_archive'));
    }
}
