<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppUser;
use App\Entity\BackupQueue;
use App\Entity\DatabaseOwner;
use App\Enum\BackupQueueStatusEnum;
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
     * Returns the oldest pending entry (FIFO: backups run in the order they were requested; the id breaks ties
     * between requests in the same second), or null if the queue is empty.
     */
    public function findOldestPending(): ?BackupQueue
    {
        $result = $this->createQueryBuilder('b')
            ->andWhere('b.status = :pending')
            ->setParameter('pending', BackupQueueStatusEnum::PENDING)
            ->orderBy('b.requestDate', \SortDirection::Ascending)
            ->addOrderBy('b.id', \SortDirection::Ascending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $result instanceof BackupQueue ? $result : null;
    }

    /**
     * Atomically takes $entry for processing (pending → running, one more attempt): a conditional UPDATE
     * succeeds for exactly one worker, so concurrent cron runs never process the same entry twice.
     */
    public function claim(BackupQueue $entry, \DateTime $now): bool
    {
        $updated = $this->createQueryBuilder('b')
            ->update()
            ->set('b.status', ':running')
            ->set('b.startedDate', ':now')
            ->set('b.attempts', 'b.attempts + 1')
            ->where('b.id = :id')
            ->andWhere('b.status = :pending')
            ->setParameter('running', BackupQueueStatusEnum::RUNNING)
            ->setParameter('pending', BackupQueueStatusEnum::PENDING)
            ->setParameter('now', $now)
            ->setParameter('id', $entry->getId())
            ->getQuery()
            ->execute();

        if (1 !== $updated) {
            return false;
        }

        // Keep the managed entity consistent with the row just updated (a later flush writes these values back).
        $entry->setStatus(BackupQueueStatusEnum::RUNNING)
            ->setStartedDate($now)
            ->setAttempts($entry->getAttempts() + 1);

        return true;
    }

    /**
     * Puts a failed entry back in the queue, keeping its attempts; false when it is not failed (any more):
     * a conditional UPDATE, so a double click or two users never queue it twice.
     */
    public function retry(BackupQueue $entry): bool
    {
        $updated = $this->createQueryBuilder('b')
            ->update()
            ->set('b.status', ':pending')
            ->set('b.error', 'NULL')
            ->set('b.startedDate', 'NULL')
            ->where('b.id = :id')
            ->andWhere('b.status = :failed')
            ->setParameter('pending', BackupQueueStatusEnum::PENDING)
            ->setParameter('failed', BackupQueueStatusEnum::FAILED)
            ->setParameter('id', $entry->getId())
            ->getQuery()
            ->execute();

        if (1 !== $updated) {
            return false;
        }

        $entry->setStatus(BackupQueueStatusEnum::PENDING)->setError(error: null)->setStartedDate(startedDate: null);

        return true;
    }

    /**
     * The most recent requests, newest first: every request for $everything (admins), otherwise those on a
     * database $user owns on that server (the same rule as the visibility of the dumps).
     *
     * @return list<BackupQueue>
     */
    public function findRecentVisibleTo(AppUser $user, bool $everything, int $limit): array
    {
        $qb = $this->createQueryBuilder('b')
            ->addSelect('s')
            ->join('b.sqlClient', 's')
            ->orderBy('b.requestDate', \SortDirection::Descending)
            ->addOrderBy('b.id', \SortDirection::Descending)
            ->setMaxResults($limit);

        if (!$everything) {
            $qb->andWhere(\sprintf(
                'EXISTS (SELECT d.id FROM %s d WHERE d.sqlClient = b.sqlClient AND d.dbName = b.dbName AND d.owner = :user)',
                DatabaseOwner::class,
            ))->setParameter('user', $user);
        }

        /** @var list<BackupQueue> $entries */
        $entries = $qb->getQuery()->getResult();

        return $entries;
    }
}
