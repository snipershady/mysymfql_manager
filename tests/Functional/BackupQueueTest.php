<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Command\ProcessBackupQueueCommand;
use App\Component\DatabaseClientConnection;
use App\Controller\BackupQueueController;
use App\Controller\DefaultController;
use App\Entity\AppUser;
use App\Entity\BackupQueue;
use App\Enum\BackupQueueStatusEnum;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Tests\Fixtures\TestCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Backup queue as the user sees it: request from the panel → cron command → status in the dashboard,
 * failed entries with their error and the "Riprova" button, visibility and CSRF of the retry.
 */
#[CoversClass(BackupQueueController::class)]
#[CoversClass(DefaultController::class)]
#[CoversClass(ProcessBackupQueueCommand::class)]
final class BackupQueueTest extends FunctionalTestCase
{
    private string $server;

    private string $ownedDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = self::env('TEST_SERVER_NAME');
        $this->ownedDb = self::env('TEST_DB_NAME_ONE');
    }

    protected function tearDown(): void
    {
        new DatabaseSchemaRepository($this->sqlClient($this->server))->dropDatabase($this->ownedDb);
        $dumpDir = \sprintf('%s/%d/%s', self::env('BACKUP_PATH'), (int) $this->sqlClient($this->server)->getId(), $this->ownedDb);
        foreach (glob($dumpDir . '/bkp_*') ?: [] as $file) {
            unlink($file);
        }
        DatabaseClientConnection::reset();
        parent::tearDown();
    }

    public function testQueuedBackupRunsAndShowsAsCompletedInTheDashboard(): void
    {
        if (null === new ExecutableFinder()->find('mysqldump')) {
            self::markTestSkipped('mysqldump is not installed.');
        }
        new DatabaseSchemaRepository($this->sqlClient($this->server))->createDatabase($this->ownedDb);
        $this->loginAs(TestCatalog::OWNER);

        $this->schemaPost('/schema/enqueue-backup', ['name' => $this->server, 'db_name' => $this->ownedDb]);
        $this->assertTrue($this->json()['is_valid']);
        $this->assertDashboardStatus(BackupQueueStatusEnum::PENDING);

        $command = new CommandTester(new Application($this->client->getKernel())->find('app:process-backup-queue'));
        $this->assertSame(Command::SUCCESS, $command->execute([]), $command->getDisplay());

        $this->assertDashboardStatus(BackupQueueStatusEnum::DONE);
        self::assertSelectorTextContains('#backup-queue', 'completato');
    }

    public function testFailedEntryShowsItsErrorAndGoesBackToTheQueue(): void
    {
        $entry = $this->failedEntry();
        $this->loginAs(TestCatalog::OWNER);

        $crawler = $this->client->request('GET', '/');
        self::assertSelectorTextContains('#backup-queue', "mysqldump: Got error: 1044: Access denied for user 'panel'");
        $this->client->submit($crawler->filter('#backup-queue form')->form());

        self::assertResponseRedirects('/', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#backup-queue .alert', 'Backup rimesso in coda');
        $this->assertDashboardStatus(BackupQueueStatusEnum::PENDING);
        $this->assertSame(BackupQueueStatusEnum::PENDING, $this->reload($entry)->getStatus());
        $this->assertSame(1, $this->reload($entry)->getAttempts(), 'a retry keeps the attempts count');
    }

    public function testAUserWhoDoesNotOwnTheDatabaseNeitherSeesNorRetriesTheEntry(): void
    {
        $entry = $this->failedEntry();
        $this->loginAs(TestCatalog::OWNER);
        $token = (string) $this->client->request('GET', '/')->filter('#backup-queue input[name="_token"]')->attr('value');

        $this->loginAs(TestCatalog::OTHER);
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('#backup-queue [data-queue-status]');

        $this->client->request('POST', '/backup-queue/' . $entry->getId() . '/retry', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        $this->assertSame(BackupQueueStatusEnum::FAILED, $this->reload($entry)->getStatus());
    }

    public function testRetryWithoutAValidTokenChangesNothing(): void
    {
        $entry = $this->failedEntry();
        $this->loginAs(TestCatalog::OWNER);

        $this->client->request('POST', '/backup-queue/' . $entry->getId() . '/retry', ['_token' => 'forged']);

        // Rejected by #[IsCsrfTokenValid] before the action (the firewall turns it into a redirect): no 303 to the
        // dashboard, no flash, entry untouched.
        $this->assertNotSame(Response::HTTP_SEE_OTHER, $this->client->getResponse()->getStatusCode());
        $this->assertSame(BackupQueueStatusEnum::FAILED, $this->reload($entry)->getStatus());
    }

    private function failedEntry(): BackupQueue
    {
        $owner = $this->entityManager()->getRepository(AppUser::class)->findOneBy(['username' => TestCatalog::OWNER]);
        $this->assertInstanceOf(AppUser::class, $owner);
        $entry = new BackupQueue()
            ->setSqlClient($this->sqlClient($this->server))
            ->setDbName($this->ownedDb)
            ->setOwner($owner)
            ->setStatus(BackupQueueStatusEnum::RUNNING)
            ->setAttempts(1)
            ->markFailed("mysqldump: Got error: 1044: Access denied for user 'panel'@'%' to database 'x'");
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();

        return $entry;
    }

    private function reload(BackupQueue $entry): BackupQueue
    {
        $this->entityManager()->clear();
        $fresh = $this->entityManager()->find(BackupQueue::class, $entry->getId());
        $this->assertInstanceOf(BackupQueue::class, $fresh);

        return $fresh;
    }

    private function assertDashboardStatus(BackupQueueStatusEnum $status): void
    {
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#backup-queue [data-queue-status="' . $status->value . '"] .badge', $status->label());
    }
}
