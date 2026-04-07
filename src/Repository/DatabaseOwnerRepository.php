<?php

namespace App\Repository;

use App\Entity\AppUser;
use App\Entity\DatabaseOwner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DatabaseOwner>
 */
class DatabaseOwnerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DatabaseOwner::class);
    }

    /**
     * @return DatabaseOwner[]
     */
    public function findAllByOwner(AppUser $user): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('d.sqlClient', 'ASC')
            ->addOrderBy('d.dbName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
