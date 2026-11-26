<?php

namespace App\Repository;

use App\Entity\AppUser;
use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
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
            ->orderBy('d.sqlClient', \SortDirection::Ascending)
            ->addOrderBy('d.dbName', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Names of the databases that $user owns on $sqlClient.
     *
     * @return list<string>
     */
    public function findDbNamesOwnedOn(AppUser $user, SqlClient $sqlClient): array
    {
        /** @var list<array{dbName: string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.dbName')
            ->where('d.owner = :user')
            ->andWhere('d.sqlClient = :sqlClient')
            ->setParameter('user', $user)
            ->setParameter('sqlClient', $sqlClient)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'dbName');
    }

    /**
     * Removes every ownership of $dbName on $sqlClient (all users); returns the number of rows deleted.
     */
    public function removeAllFor(SqlClient $sqlClient, string $dbName): int
    {
        return $this->createQueryBuilder('d')
            ->delete()
            ->where('d.sqlClient = :sqlClient')
            ->andWhere('d.dbName = :dbName')
            ->setParameter('sqlClient', $sqlClient)
            ->setParameter('dbName', $dbName)
            ->getQuery()
            ->execute();
    }
}
