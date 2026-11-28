<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Works out which database a legacy flat dump (BACKUP_PATH/bkp_<date>_<db>_<table|full>.sql) belongs to.
 *
 * The name alone is ambiguous ("bkp_…_app_old_users.sql" may be database "app" + table "old_users" or
 * "app_old" + "users"), so it is matched against the databases known on the target server; when more
 * than one reading remains, readings whose table does not exist are discarded. The caller moves the
 * file only when exactly one reading is left.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @see \App\Tests\Service\LegacyBackupNameResolverTest
 */
final class LegacyBackupNameResolver
{
    private const string PATTERN = '/^bkp_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}_(\w+)\.sql(?:\.gz|\.zst)?$/';

    /**
     * @param list<string>                              $knownDbs    databases that may own the dump
     * @param callable(string $db, string $table): bool $tableExists whether $table exists in $db (only asked to break ties)
     *
     * @return list<array{db: string, table: string|null}> the remaining readings (table null = full database dump)
     */
    public static function resolve(string $filename, array $knownDbs, callable $tableExists): array
    {
        if (1 !== preg_match(self::PATTERN, $filename, $matches)) {
            return [];
        }

        $rest = $matches[1];
        $readings = [];
        foreach (array_unique($knownDbs) as $db) {
            if (1 !== preg_match('/^\w+$/', $db)) {
                continue;
            }

            if ($rest === $db . '_full') {
                $readings[] = ['db' => $db, 'table' => null];
            } elseif (str_starts_with($rest, $db . '_') && \strlen($rest) > \strlen($db) + 1) {
                $readings[] = ['db' => $db, 'table' => substr($rest, \strlen($db) + 1)];
            }
        }

        if (\count($readings) <= 1) {
            return $readings;
        }

        return array_values(array_filter(
            $readings,
            static fn (array $r): bool => null === $r['table'] || $tableExists($r['db'], $r['table'])
        ));
    }
}
