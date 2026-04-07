<?php

namespace App\Repository;

use App\Entity\AppUser;
use App\Entity\SqlClient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SqlClient>
 */
class SqlClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SqlClient::class);
    }

    public function findOneByName(string $name): ?SqlClient
    {
        return $this->createQueryBuilder('s')
            ->where('s.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return SqlClient[]
     */
    public function findAllOwned(AppUser $user): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.owner', 'o')
            ->where('o = :user')
            ->setParameter('user', $user)
            ->orderBy('s.host', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
