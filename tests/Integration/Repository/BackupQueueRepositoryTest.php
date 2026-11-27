<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AppUser;
use App\Entity\BackupQueue;
use App\Entity\SqlClient;
use App\Repository\BackupQueueRepository;
use App\Tests\Fixtures\TestCatalog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Queue order and atomic claim on the real test catalog (rolled back after each test).
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
        $this->assertTrue($this->repository->claim($oldest));
        $this->assertSame($middle, $this->repository->findOldestPending());
        $this->assertTrue($this->repository->claim($middle));
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
        $this->assertTrue($repository->claim($entry));
        $this->assertTrue($entry->isDequeued());

        // A second worker holding a stale copy of the same row loses the race.
        $stale = clone $entry;
        $stale->setIsDequeued(isDequeued: false);
        $this->assertFalse($repository->claim($stale));

        $this->assertNull($repository->findOldestPending());
    }

    private function enqueue(string $requestDate): BackupQueue
    {
        $entry = new BackupQueue()
            ->setDbName($_ENV['TEST_DB_NAME_ONE'])
            ->setSqlClient($this->entityManager->getRepository(SqlClient::class)->findOneBy(['name' => $_ENV['TEST_SERVER_NAME']]))
            ->setOwner($this->entityManager->getRepository(AppUser::class)->findOneBy(['username' => TestCatalog::OWNER]))
            ->setRequestDate(new \DateTime($requestDate));
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}
