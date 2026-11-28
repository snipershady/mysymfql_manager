<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AppUser;
use App\Entity\BackupQueue;
use App\Entity\SqlClient;
use App\Enum\BackupQueueStatusEnum;
use App\Repository\BackupQueueRepository;
use App\Tests\Fixtures\TestCatalog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Queue order, atomic claim and retry, visibility of the requests, on the real test catalog
 * (rolled back after each test).
 */
#[CoversClass(BackupQueueRepository::class)]
final class BackupQueueRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private BackupQueueRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $repository = $entityManager->getRepository(BackupQueue::class);
        $this->assertInstanceOf(BackupQueueRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testTheOldestPendingEntryComesFirst(): void
    {
        $newest = $this->enqueue('2026-11-27 10:00:00');
        $oldest = $this->enqueue('2026-11-27 08:00:00');
        $middle = $this->enqueue('2026-11-27 09:00:00');

        $this->assertSame($oldest, $this->repository->findOldestPending());
        $this->assertTrue($this->repository->claim($oldest, new \DateTime()));
        $this->assertSame($middle, $this->repository->findOldestPending());
        $this->assertTrue($this->repository->claim($middle, new \DateTime()));
        $this->assertSame($newest, $this->repository->findOldestPending());
    }

    public function testRequestsInTheSameSecondKeepTheirInsertionOrder(): void
    {
        $first = $this->enqueue('2026-11-27 08:00:00');
        $this->enqueue('2026-11-27 08:00:00');

        $this->assertSame($first, $this->repository->findOldestPending());
    }

    public function testAnEntryCanBeClaimedOnlyOnce(): void
    {
        $repository = $this->repository;
        $entry = $this->enqueue('2026-11-27 08:00:00');

        $this->assertSame($entry, $repository->findOldestPending());
        $now = new \DateTime('2026-11-27 10:00:00');
        $this->assertTrue($repository->claim($entry, $now));
        $this->assertSame(BackupQueueStatusEnum::RUNNING, $entry->getStatus());

        // A second worker holding a stale copy of the same row loses the race.
        $stale = clone $entry;
        $stale->setStatus(BackupQueueStatusEnum::PENDING);
        $this->assertFalse($repository->claim($stale, $now));

        $this->assertNull($repository->findOldestPending());
        $this->assertSame(['running', 1, '2026-11-27 10:00:00'], $this->row($entry));
    }

    public function testOnlyEntriesRunningForTooLongAreMarkedFailed(): void
    {
        $interrupted = $this->enqueue('2026-11-27 08:00:00');
        $this->assertTrue($this->repository->claim($interrupted, new \DateTime('2026-11-27 08:00:00')));
        $recent = $this->enqueue('2026-11-27 09:00:00');
        $this->assertTrue($this->repository->claim($recent, new \DateTime('2026-11-27 19:00:00')));
        $pending = $this->enqueue('2026-11-27 07:00:00');

        $this->assertSame(1, $this->repository->failStale(new \DateTime('2026-11-27 12:00:00'), 'Interrotto.'));

        $this->assertSame(['failed', 1, '2026-11-27 08:00:00'], $this->row($interrupted));
        $this->assertSame('running', $this->row($recent)[0]);
        $this->assertSame('pending', $this->row($pending)[0]);
        $this->assertSame('Interrotto.', $this->entityManager->getConnection()->fetchOne('SELECT error FROM backup_queue WHERE id = ?', [$interrupted->getId()]));

        // Then it can be retried from the dashboard like any failed entry.
        $this->entityManager->refresh($interrupted);
        $this->assertTrue($this->repository->retry($interrupted));
    }

    public function testOnlyAFailedEntryGoesBackToTheQueueAndKeepsItsAttempts(): void
    {
        $entry = $this->enqueue('2026-11-27 08:00:00');
        $this->assertFalse($this->repository->retry($entry), 'a pending entry is not retried');

        $this->repository->claim($entry, new \DateTime());
        $entry->markFailed('mysqldump: Got error: 1044');
        $this->entityManager->flush();

        $this->assertTrue($this->repository->retry($entry));
        $this->assertFalse($this->repository->retry($entry), 'a second click changes nothing');
        $this->assertSame(BackupQueueStatusEnum::PENDING, $entry->getStatus());
        $this->assertNull($entry->getError());
        $this->assertSame(['pending', 1, null], $this->row($entry));
        $this->assertSame($entry, $this->repository->findOldestPending());
    }

    public function testRecentRequestsAreLimitedToOwnedDatabasesExceptForAdmins(): void
    {
        $owned = $this->enqueue('2026-11-27 08:00:00');
        $notOwned = $this->enqueue('2026-11-27 09:00:00', $_ENV['TEST_DB_NAME_TWO']);
        $owner = $this->user(TestCatalog::OWNER);

        $this->assertSame([$notOwned, $owned], $this->repository->findRecentVisibleTo($owner, everything: true, limit: 10));
        $this->assertSame([$owned], $this->repository->findRecentVisibleTo($owner, everything: false, limit: 10));
        $this->assertSame([], $this->repository->findRecentVisibleTo($this->user(TestCatalog::OTHER), everything: false, limit: 10));
        $this->assertSame([$notOwned], $this->repository->findRecentVisibleTo($owner, everything: true, limit: 1));
    }

    /**
     * The row as stored (not the managed entity): status, attempts, started_date.
     *
     * @return array{mixed, mixed, mixed}
     */
    private function row(BackupQueue $entry): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT status, attempts, started_date FROM backup_queue WHERE id = ?', [$entry->getId()]
        );
        $this->assertIsArray($row);

        return [$row['status'], (int) $row['attempts'], $row['started_date']];
    }

    private function user(string $username): AppUser
    {
        $user = $this->entityManager->getRepository(AppUser::class)->findOneBy(['username' => $username]);
        $this->assertInstanceOf(AppUser::class, $user);

        return $user;
    }

    private function enqueue(string $requestDate, ?string $dbName = null): BackupQueue
    {
        $entry = new BackupQueue()
            ->setDbName($dbName ?? $_ENV['TEST_DB_NAME_ONE'])
            ->setSqlClient($this->entityManager->getRepository(SqlClient::class)->findOneBy(['name' => $_ENV['TEST_SERVER_NAME']]))
            ->setOwner($this->user(TestCatalog::ADMIN))
            ->setRequestDate(new \DateTime($requestDate));
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}
