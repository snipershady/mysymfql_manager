<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BackupQueue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BackupQueue>
 */
class BackupQueueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackupQueue::class);
    }

    /**
     * Returns the oldest entry that has not been dequeued yet (FIFO: backups run in the order they were
     * requested; the id breaks ties between requests in the same second), or null if the queue is empty.
     */
    public function findOldestPending(): ?BackupQueue
    {
        $result = $this->createQueryBuilder('b')
            ->andWhere('b.isDequeued = :dequeued')
            ->setParameter('dequeued', value: false)
            ->orderBy('b.requestDate', \SortDirection::Ascending)
            ->addOrderBy('b.id', \SortDirection::Ascending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $result instanceof BackupQueue ? $result : null;
    }

    /**
     * Atomically takes $entry for processing: a conditional UPDATE succeeds for exactly one worker,
     * so concurrent cron runs never process the same entry twice.
     */
    public function claim(BackupQueue $entry): bool
    {
        $updated = $this->createQueryBuilder('b')
            ->update()
            ->set('b.isDequeued', ':dequeued')
            ->where('b.id = :id')
            ->andWhere('b.isDequeued = :pending')
            ->setParameter('dequeued', value: true)
            ->setParameter('pending', value: false)
            ->setParameter('id', $entry->getId())
            ->getQuery()
            ->execute();

        if (1 !== $updated) {
            return false;
        }

        // Keep the managed entity consistent with the row just updated.
        $entry->setIsDequeued(isDequeued: true);

        return true;
    }
}
