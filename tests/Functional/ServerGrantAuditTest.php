<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Command\CheckServerGrantsCommand;
use App\Component\DatabaseClientConnection;
use App\Controller\ServerGrantController;
use App\RepositoryPDO\MysqlAccountRepository;
use App\Service\ServerGrantAudit;
use App\Tests\Fixtures\TestCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server grant check against the real MySQL server: the fixture servers use a broad test account (danger), and a
 * real account granted only on the database of the server's user is reported as OK.
 */
#[CoversClass(ServerGrantController::class)]
#[CoversClass(ServerGrantAudit::class)]
#[CoversClass(CheckServerGrantsCommand::class)]
final class ServerGrantAuditTest extends FunctionalTestCase
{
    private string $restrictedUser;

    private MysqlAccountRepository $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restrictedUser = 'ut_grants_' . bin2hex(random_bytes(3));
        $this->accounts = new MysqlAccountRepository($this->sqlClient(self::env('TEST_SERVER_NAME')));
    }

    protected function tearDown(): void
    {
        $this->accounts->dropUser($this->restrictedUser, $this->accountHost());
        DatabaseClientConnection::reset();
        parent::tearDown();
    }

    public function testBroadAccountUsedByANonAdminIsReported(): void
    {
        $this->loginAs(TestCatalog::ADMIN);

        $crawler = $this->client->request('GET', '/admin/server-grants');

        self::assertResponseIsSuccessful();
        $card = $crawler->filter(\sprintf('[data-server-grants="%s"]', self::env('TEST_SERVER_NAME')));
        $this->assertSame('danger', $card->attr('data-severity'));
        $this->assertStringContainsString('Privilegi globali', $card->text());
        $this->assertStringContainsString(TestCatalog::OWNER, $card->text());
    }

    public function testAccountGrantedOnlyOnTheUserDatabaseIsOk(): void
    {
        $host = $this->accountHost();
        $password = 'Grants-' . bin2hex(random_bytes(8));
        $this->accounts->createUser($this->restrictedUser, $password, $host);
        $this->accounts->grantPrivileges(self::env('TEST_DB_NAME_TWO'), $this->restrictedUser, 'ALL PRIVILEGES', $host);

        // server_other (owner test_other, database TEST_DB_NAME_TWO) switches to the restricted account. Not
        // flushed: the listener encrypts the password in the entity on flush.
        $server = $this->sqlClient(TestCatalog::OTHER_SERVER)->setUsername($this->restrictedUser)->setPassword($password);

        $report = $this->service()->check($server);

        $this->assertNull($report->error);
        $this->assertSame($this->restrictedUser . '@' . $host, $report->account);
        $this->assertSame('ok', $report->severity()->value, implode("\n", array_column($report->findings, 'message')));
    }

    public function testUnreachableServerIsReportedWithItsError(): void
    {
        $server = $this->sqlClient(TestCatalog::OTHER_SERVER)->setHost('127.0.0.1')->setPort(1);

        $report = $this->service()->check($server);

        $this->assertSame('danger', $report->severity()->value);
        $this->assertStringContainsString('2002', (string) $report->error);
    }

    public function testOnlyAdminsSeeThePage(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->client->request('GET', '/admin/server-grants');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testCommandFailsOnDanger(): void
    {
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('app:check-server-grants'));

        $this->assertSame(Command::FAILURE, $tester->execute(['name' => self::env('TEST_SERVER_NAME')]));
        $this->assertStringContainsString('DANGER', $tester->getDisplay());
        $this->assertSame(Command::INVALID, $tester->execute(['name' => 'no_such_server']));
    }

    private function service(): ServerGrantAudit
    {
        $service = self::getContainer()->get(ServerGrantAudit::class);
        $this->assertInstanceOf(ServerGrantAudit::class, $service);

        return $service;
    }

    /** Account host matching how the tests connect: the socket for "localhost", TCP otherwise. */
    private function accountHost(): string
    {
        return 'localhost' === self::env('TEST_SERVER_HOST') ? 'localhost' : '%';
    }
}
