<?php

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
     * Returns the most recently queued entry that has not been dequeued yet, or null if the queue is empty.
     */
    public function findLastOneDequeable(): ?BackupQueue
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.isDequeued = :dequeued')
            ->setParameter('dequeued', false)
            ->orderBy('b.requestDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
}
