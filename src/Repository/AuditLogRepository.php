<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\Input\AuditLogFilter;
use App\Entity\AuditLogEntry;
use App\Enum\AuditActionEnum;
use App\Enum\AuditOutcomeEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLogEntry>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public const string TABLE = 'audit_log';

    public const int PAGE_SIZE = 50;

    /** Longest retention allowed: older entries are deleted by app:audit-prune (decision: at most 3 months). */
    public const int MAX_RETENTION_DAYS = 90;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLogEntry::class);
    }

    /**
     * Saves an entry with a plain DBAL insert, outside the unit of work: the entry is written even when the
     * audited action rolled back its transaction or left the EntityManager closed.
     */
    public function record(AuditLogEntry $entry): void
    {
        $this->getEntityManager()->getConnection()->insert(self::TABLE, [
            'occurred_at' => $entry->getOccurredAt(),
            'actor_id' => $entry->getActorId(),
            'actor_username' => $entry->getActorUsername(),
            'ip' => $entry->getIp(),
            'action' => $entry->getAction()->value,
            'outcome' => $entry->getOutcome()->value,
            'server_name' => $entry->getServerName(),
            'db_name' => $entry->getDbName(),
            'target' => $entry->getTarget(),
            'details' => $entry->getDetails(),
            'message' => $entry->getMessage(),
        ], [
            'occurred_at' => Types::DATETIME_IMMUTABLE,
            'actor_id' => Types::INTEGER,
            'details' => Types::JSON,
        ]);
    }

    /**
     * One page of entries matching the filters, most recent first.
     *
     * @return Paginator<AuditLogEntry>
     */
    public function search(AuditLogFilter $filter): Paginator
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.occurredAt', \SortDirection::Descending)
            ->addOrderBy('a.id', \SortDirection::Descending)
            ->setFirstResult(($filter->page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE);

        if (null !== $filter->actor) {
            $qb->andWhere('a.actorUsername = :actor')->setParameter('actor', $filter->actor);
        }

        if (null !== $filter->server) {
            $qb->andWhere('a.serverName = :server')->setParameter('server', $filter->server);
        }

        $action = $filter->actionEnum();
        if ($action instanceof AuditActionEnum) {
            $qb->andWhere('a.action = :action')->setParameter('action', $action);
        }

        $outcome = $filter->outcomeEnum();
        if ($outcome instanceof AuditOutcomeEnum) {
            $qb->andWhere('a.outcome = :outcome')->setParameter('outcome', $outcome);
        }

        $from = $filter->fromDate();
        if ($from instanceof \DateTimeImmutable) {
            $qb->andWhere('a.occurredAt >= :from')->setParameter('from', $from, Types::DATETIME_IMMUTABLE);
        }

        $to = $filter->toDateExclusive();
        if ($to instanceof \DateTimeImmutable) {
            $qb->andWhere('a.occurredAt < :to')->setParameter('to', $to, Types::DATETIME_IMMUTABLE);
        }

        return new Paginator($qb->getQuery(), fetchJoinCollection: false);
    }

    /**
     * Number of entries older than $cutoff and the date of the oldest one.
     *
     * @return array{count: int, oldest: ?\DateTimeImmutable}
     */
    public function statsOlderThan(\DateTimeImmutable $cutoff): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS cnt, MIN(occurred_at) AS oldest FROM ' . self::TABLE . ' WHERE occurred_at < ?',
            [$cutoff],
            [Types::DATETIME_IMMUTABLE],
        );

        $count = \is_array($row) && is_numeric($row['cnt'] ?? null) ? (int) $row['cnt'] : 0;
        $oldest = \is_array($row) && \is_string($row['oldest'] ?? null) ? new \DateTimeImmutable($row['oldest']) : null;

        return ['count' => $count, 'oldest' => $oldest];
    }

    /**
     * Deletes the entries older than $cutoff and returns how many were deleted.
     */
    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE occurred_at < ?',
            [$cutoff],
            [Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Distinct usernames and server names present in the log, for the filter drop-downs.
     *
     * @return array{actors: list<string>, servers: list<string>}
     */
    public function filterValues(): array
    {
        $connection = $this->getEntityManager()->getConnection();

        /** @var list<string> $actors */
        $actors = $connection->fetchFirstColumn('SELECT DISTINCT actor_username FROM ' . self::TABLE . ' ORDER BY actor_username');
        /** @var list<string> $servers */
        $servers = $connection->fetchFirstColumn('SELECT DISTINCT server_name FROM ' . self::TABLE . ' WHERE server_name IS NOT NULL ORDER BY server_name');

        return ['actors' => $actors, 'servers' => $servers];
    }
}
