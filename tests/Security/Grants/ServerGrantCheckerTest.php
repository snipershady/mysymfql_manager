<?php

declare(strict_types=1);

namespace App\Tests\Security\Grants;

use App\Entity\AppUser;
use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
use App\Enum\SeverityEnum;
use App\Security\Grants\GrantStatementParser;
use App\Security\Grants\ParsedGrants;
use App\Security\Grants\ServerGrantChecker;
use App\Security\Grants\ServerGrantReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerGrantChecker::class)]
#[CoversClass(ServerGrantReport::class)]
#[CoversClass(SeverityEnum::class)]
#[UsesClass(GrantStatementParser::class)]
#[UsesClass(ParsedGrants::class)]
final class ServerGrantCheckerTest extends TestCase
{
    private const string USAGE = 'GRANT USAGE ON *.* TO `p`@`%`';

    public function testAccountGrantedOnlyOnAssignedDatabasesIsOk(): void
    {
        $mario = $this->user('mario');
        $server = $this->server($mario);

        $report = $this->check($server, [self::USAGE, 'GRANT ALL PRIVILEGES ON `shop`.* TO `p`@`%`', 'GRANT SELECT ON `shop\_log`.* TO `p`@`%`'], [
            $this->assign($server, $mario, 'shop'),
            $this->assign($server, $mario, 'shop_log'),
        ]);

        $this->assertSame(SeverityEnum::OK, $report->severity());
        $this->assertSame(['mario'], $report->nonAdminUsers);
        $this->assertSame(['shop', 'shop_log'], $report->assignedDbs);
        $this->assertSame('p@%', $report->account);
    }

    public function testBroadPrivilegesAreFineWhenOnlyAdminsUseTheServer(): void
    {
        $admin = $this->user('root', admin: true);
        $server = $this->server($admin);

        $report = $this->check($server, ['GRANT ALL PRIVILEGES ON *.* TO `p`@`%` WITH GRANT OPTION'], [$this->assign($server, $admin, 'shop')]);

        $this->assertSame(SeverityEnum::INFO, $report->severity());
        $this->assertStringContainsString('Usato solo da admin', $report->findings[0]['message']);
        $this->assertSame([], $report->nonAdminUsers);

        $this->assertSame('Usato solo da admin.', $this->check($server, [self::USAGE], [])->findings[0]['message']);
    }

    public function testEverythingAUserCouldReachBeyondItsDatabases(): void
    {
        $mario = $this->user('mario');
        $server = $this->server($mario);

        $report = $this->check($server, [
            'GRANT SELECT, PROCESS ON *.* TO `p`@`%`',
            'GRANT ALL PRIVILEGES ON `shop`.* TO `p`@`%` WITH GRANT OPTION',
            'GRANT SELECT ON `crm`.* TO `p`@`%`',
            'GRANT SELECT ON `app_%`.* TO `p`@`%`',
            'GRANT `reporting`@`%` TO `p`@`%`',
            'GRANT PROXY ON ``@`` TO `p`@`%`',
            'GRANT SOMETHING NEW',
        ], [$this->assign($server, $mario, 'shop')]);

        $this->assertSame(SeverityEnum::DANGER, $report->severity());
        $messages = implode("\n", array_map(static fn (array $f): string => $f['severity']->value . ' ' . $f['message'], $report->findings));
        $this->assertStringContainsString('danger Privilegi globali (SELECT, PROCESS)', $messages);
        $this->assertStringContainsString('danger Ruoli assegnati', $messages);
        $this->assertStringContainsString('danger Privilegio PROXY', $messages);
        $this->assertStringContainsString('danger Grant con wildcard su `app_%`', $messages);
        $this->assertStringContainsString('danger Grant sul database `crm`', $messages);
        $this->assertStringContainsString('warning WITH GRANT OPTION', $messages);
        $this->assertStringContainsString('warning Grant non riconosciuto, da verificare a mano: GRANT SOMETHING NEW', $messages);
        $this->assertStringNotContainsString('`shop`', $messages);
    }

    public function testAnAccountSharedByUsersWithDifferentDatabases(): void
    {
        $mario = $this->user('mario');
        $anna = $this->user('anna');
        $luca = $this->user('luca');
        $server = $this->server($mario, $anna, $luca);
        $grants = [self::USAGE, 'GRANT ALL PRIVILEGES ON `shop`.* TO `p`@`%`', 'GRANT ALL PRIVILEGES ON `crm`.* TO `p`@`%`'];

        // luca owns the server but no database: he cannot use the query console, he is not compared.
        $report = $this->check($server, $grants, [$this->assign($server, $mario, 'shop'), $this->assign($server, $anna, 'crm')]);
        $this->assertSame(SeverityEnum::DANGER, $report->severity());
        $this->assertStringContainsString('Account condiviso da utenti non admin con database diversi (anna, mario)', $report->findings[0]['message']);

        // Same databases for both: sharing the account gives nothing more.
        $same = $this->check($server, $grants, [
            $this->assign($server, $mario, 'shop'), $this->assign($server, $mario, 'crm'),
            $this->assign($server, $anna, 'crm'), $this->assign($server, $anna, 'shop'),
        ]);
        $this->assertSame(SeverityEnum::OK, $same->severity());
        $this->assertSame(['anna', 'luca', 'mario'], $same->nonAdminUsers);
    }

    public function testUnreachableServer(): void
    {
        $report = new ServerGrantChecker()->unreachable($this->server(), 'SQLSTATE[HY000] [2002] Connection refused');

        $this->assertSame(SeverityEnum::DANGER, $report->severity());
        $this->assertNull($report->account);
        foreach (SeverityEnum::cases() as $case) {
            $this->assertNotSame('', $case->label());
            $this->assertNotSame('', $case->color());
        }
    }

    /**
     * @param list<string>        $grants
     * @param list<DatabaseOwner> $assignments
     */
    private function check(SqlClient $server, array $grants, array $assignments): ServerGrantReport
    {
        return new ServerGrantChecker()->check($server, 'p@%', $grants, $assignments);
    }

    private function user(string $username, bool $admin = false): AppUser
    {
        return new AppUser()->setUsername($username)->setRoles([$admin ? 'ROLE_ADMIN' : 'ROLE_USER']);
    }

    private function server(AppUser ...$owners): SqlClient
    {
        $server = new SqlClient()->setName('srv');
        foreach ($owners as $owner) {
            $server->addOwner($owner);
        }

        return $server;
    }

    private function assign(SqlClient $server, AppUser $owner, string $db): DatabaseOwner
    {
        return new DatabaseOwner()->setSqlClient($server)->setOwner($owner)->setDbName($db);
    }
}
