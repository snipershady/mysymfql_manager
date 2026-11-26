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
 * Atomic claim of a queue entry on the real test catalog (rolled back after each test).
 */
#[CoversClass(BackupQueueRepository::class)]
final class BackupQueueRepositoryTest extends KernelTestCase
{
    public function testAnEntryCanBeClaimedOnlyOnce(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $repository = $entityManager->getRepository(BackupQueue::class);
        $this->assertInstanceOf(BackupQueueRepository::class, $repository);

        $entry = new BackupQueue()
            ->setDbName($_ENV['TEST_DB_NAME_ONE'])
            ->setSqlClient($entityManager->getRepository(SqlClient::class)->findOneBy(['name' => $_ENV['TEST_SERVER_NAME']]))
            ->setOwner($entityManager->getRepository(AppUser::class)->findOneBy(['username' => TestCatalog::OWNER]));
        $entityManager->persist($entry);
        $entityManager->flush();

        $this->assertSame($entry, $repository->findLastOneDequeable());
        $this->assertTrue($repository->claim($entry));
        $this->assertTrue($entry->isDequeued());

        // A second worker holding a stale copy of the same row loses the race.
        $stale = clone $entry;
        $stale->setIsDequeued(isDequeued: false);
        $this->assertFalse($repository->claim($stale));

        $this->assertNull($repository->findLastOneDequeable());
    }
}
