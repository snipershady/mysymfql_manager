<?php

declare(strict_types=1);

namespace App\Tests\Service\Audit;

use App\Dto\Input\AccountPassword;
use App\Dto\Input\GrantChanges;
use App\Dto\Input\GrantInput;
use App\Dto\Input\KillProcess;
use App\Dto\Input\NewDatabase;
use App\Dto\Input\RestoreBackup;
use App\Dto\Input\SqlStatement;
use App\Dto\Input\TableTarget;
use App\Entity\AppUser;
use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
use App\Enum\CharsetEnum;
use App\Service\Audit\AuditContext;
use App\Service\Audit\AuditContextExtractor;
use App\Service\Audit\SqlSecretRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(AuditContextExtractor::class)]
#[UsesClass(AuditContext::class)]
#[UsesClass(SqlSecretRedactor::class)]
final class AuditContextExtractorTest extends TestCase
{
    private AuditContextExtractor $extractor;

    #[\Override]
    protected function setUp(): void
    {
        $this->extractor = new AuditContextExtractor();
    }

    public function testTableTarget(): void
    {
        $context = $this->extract([new TableTarget('server_locale', 'shop', 'orders')]);

        $this->assertSame('server_locale', $context->serverName);
        $this->assertSame('shop', $context->dbName);
        $this->assertSame('orders', $context->target);
        $this->assertNull($context->details);
    }

    public function testPasswordsOfInputDtosAreNeverKept(): void
    {
        $context = $this->extract([new NewDatabase('srv', 'shop', 'shop_user', 'S3cret!', 'localhost', 'SELECT,INSERT', CharsetEnum::UTF8MB4)]);

        $this->assertSame("'shop_user'@'localhost'", $context->target);
        $this->assertSame(['privileges' => 'SELECT,INSERT', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_ai_ci'], $context->details);
        $this->assertStringNotContainsString('S3cret!', serialize($context));

        $context = $this->extract([new AccountPassword('srv', 'app', '%', 'S3cret!')]);
        $this->assertSame("'app'@'%'", $context->target);
        $this->assertNull($context->details);
    }

    public function testSqlIsRedacted(): void
    {
        $context = $this->extract([new SqlStatement('srv', 'shop', "CREATE USER x IDENTIFIED BY 'S3cret!'")]);

        $this->assertSame(['sql' => "CREATE USER x IDENTIFIED BY '***'"], $context->details);
    }

    public function testRawFieldsAreFilteredToo(): void
    {
        $request = Request::create('/x', 'POST', ['sql' => "SET PASSWORD = 'pw'", 'password' => 'pw', 'nested' => ['api_secret' => 's', 'n' => 1]]);

        $this->assertSame(['sql' => "SET PASSWORD = '***'", 'nested' => ['n' => 1]], $this->extractor->extract([], $request)->details);
    }

    public function testLongSqlIsTruncated(): void
    {
        $sql = 'SELECT ' . str_repeat('a', AuditContextExtractor::MAX_TEXT_BYTES);
        $context = $this->extract([new SqlStatement('srv', 'shop', $sql)]);

        $this->assertIsArray($context->details);
        $this->assertIsString($context->details['sql']);
        $this->assertStringEndsWith('…', $context->details['sql']);
        $this->assertLessThanOrEqual(AuditContextExtractor::MAX_TEXT_BYTES + 3, \strlen($context->details['sql']));
    }

    public function testNestedDtosAndOtherTargets(): void
    {
        $context = $this->extract([new GrantChanges('srv', 'app', 'localhost', [new GrantInput('shop', 'SELECT')], ['old'])]);
        $this->assertSame(['grants' => [['db' => 'shop', 'privileges' => 'SELECT']], 'revokedDbs' => ['old']], $context->details);

        $this->assertSame('PID 42', $this->extract([new KillProcess('srv', 42)])->target);
        $this->assertSame('3/shop/bkp.sql', $this->extract([new RestoreBackup('srv', 'shop', '3/shop/bkp.sql')])->target);
    }

    public function testPanelEntitiesAndFormFields(): void
    {
        $owner = new AppUser()->setUsername('mario');
        $sqlClient = new SqlClient()->setName('srv');
        $assignment = new DatabaseOwner()->setDbName('shop')->setOwner($owner)->setSqlClient($sqlClient);

        $request = Request::create('/admin/x', 'POST', ['app_user' => ['username' => 'mario', 'roles' => ['ROLE_USER'], 'plainPassword' => ['first' => 'pw', 'second' => 'pw'], '_token' => 't']]);

        $context = $this->extractor->extract([$request, $owner], $request);
        $this->assertSame('Utente #0 mario', $context->target);
        $this->assertSame(['app_user' => ['username' => 'mario', 'roles' => ['ROLE_USER']]], $context->details);

        $context = $this->extract([$sqlClient]);
        $this->assertSame('srv', $context->serverName);
        $this->assertSame('Server #0 srv', $context->target);

        $context = $this->extract([$assignment]);
        $this->assertSame('srv', $context->serverName);
        $this->assertSame('shop', $context->dbName);
        $this->assertSame('Assegnazione #0 a mario', $context->target);
    }

    /**
     * @param list<object> $arguments
     */
    private function extract(array $arguments): AuditContext
    {
        return $this->extractor->extract($arguments, Request::create('/schema/x', 'POST'));
    }
}
