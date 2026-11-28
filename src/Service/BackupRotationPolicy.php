<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\BackupDump;
use App\Enum\BackupCompressionEnum;

/**
 * Decides which dumps a rotation deletes: those older than the retention period, except the most recent
 * $keepLast of every series, so a database whose backups stopped (failing cron, server offline) is never
 * left without a dump.
 *
 * A series is one kind of dump of one database on one server: the full dumps of `shop` on server 3 and the
 * dumps of its `orders` table rotate independently. Legacy flat dumps (no server) form series by file name
 * without the timestamp (`bkp_<date>_<db>_<table|full>.sql` → `<db>_<table|full>`). The compression is not part of
 * the series: `.sql`, `.sql.gz` and `.sql.zst` dumps of the same database rotate together.
 *
 * Age = file modification time (the time the dump completed; preserved by app:backup-relocate).
 * Pure: no filesystem access, the caller passes the listing and the current time.
 *
 * @see \App\Tests\Service\BackupRotationPolicyTest
 */
final readonly class BackupRotationPolicy
{
    /** Prefix written by MysqldumpManager::createBackup(): "bkp_" + "Y-m-d_H-i-s" + "_". */
    private const int TIMESTAMP_PREFIX_LENGTH = 24;

    /**
     * @param list<BackupDump> $dumps
     *
     * @return list<BackupDump> the dumps to delete, oldest first
     *
     * @throws \InvalidArgumentException when $days < 1 or $keepLast < 0
     */
    public function expired(array $dumps, \DateTimeImmutable $now, int $days, int $keepLast): array
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('The retention period must be at least 1 day.');
        }

        if ($keepLast < 0) {
            throw new \InvalidArgumentException('The number of dumps to keep cannot be negative.');
        }

        $cutoff = $now->modify(\sprintf('-%d days', $days))->getTimestamp();

        /** @var array<string, list<BackupDump>> $series */
        $series = [];
        foreach ($dumps as $dump) {
            $series[self::seriesKey($dump)][] = $dump;
        }

        $expired = [];
        foreach ($series as $members) {
            usort($members, static fn (BackupDump $a, BackupDump $b): int => $b->mtime <=> $a->mtime);
            foreach (\array_slice($members, $keepLast) as $dump) {
                if ($dump->mtime < $cutoff) {
                    $expired[] = $dump;
                }
            }
        }

        usort($expired, static fn (BackupDump $a, BackupDump $b): int => $a->mtime <=> $b->mtime);

        return $expired;
    }

    /**
     * Server + database + what was dumped (table name or "full"), read from the file name after the timestamp.
     */
    public static function seriesKey(BackupDump $dump): string
    {
        $subject = substr(BackupCompressionEnum::baseName($dump->filename), self::TIMESTAMP_PREFIX_LENGTH);

        if (null === $dump->sqlClientId || null === $dump->dbName) {
            return 'legacy/' . $subject;
        }

        // Structured dumps: "<db>_<table|full>"; the database is known, so the remainder is unambiguous.
        $scope = str_starts_with($subject, $dump->dbName . '_') ? substr($subject, \strlen($dump->dbName) + 1) : $subject;

        return \sprintf('%d/%s/%s', $dump->sqlClientId, $dump->dbName, $scope);
    }
}
