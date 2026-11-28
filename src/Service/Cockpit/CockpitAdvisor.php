<?php

declare(strict_types=1);

namespace App\Service\Cockpit;

use App\Enum\SeverityEnum;

/**
 * Turns the raw metrics of the cockpits into indicators and findings. Pure: no connection, the caller passes
 * what MetricsRepository read. Thresholds are deliberately conservative (common MySQL tuning guidance) and every
 * finding says what to look at, not what to change blindly.
 *
 * @phpstan-type Finding array{severity: SeverityEnum, message: string}
 *
 * @see \App\Tests\Service\Cockpit\CockpitAdvisorTest
 */
final class CockpitAdvisor
{
    /** Counters the browser turns into rates per second between two refreshes. */
    public const array RATE_COUNTERS = [
        'Questions', 'Com_select', 'Com_insert', 'Com_update', 'Com_delete', 'Bytes_received', 'Bytes_sent',
        'Slow_queries', 'Innodb_rows_read', 'Innodb_rows_inserted', 'Innodb_rows_updated', 'Innodb_rows_deleted',
        'Created_tmp_disk_tables', 'Connections',
    ];

    /** Configuration shown in the server cockpit. */
    public const array CONFIG_VARIABLES = [
        'version', 'version_comment', 'hostname', 'port', 'max_connections', 'innodb_buffer_pool_size',
        'innodb_redo_log_capacity', 'innodb_flush_log_at_trx_commit', 'sync_binlog', 'log_bin', 'binlog_format',
        'gtid_mode', 'read_only', 'super_read_only', 'slow_query_log', 'long_query_time', 'performance_schema',
        'character_set_server', 'collation_server', 'time_zone', 'transaction_isolation', 'sql_mode',
        'tmp_table_size', 'max_heap_table_size', 'table_open_cache', 'wait_timeout', 'max_allowed_packet',
        // Percona Server only (shown when present)
        'userstat', 'thread_statistics', 'log_slow_verbosity', 'log_slow_rate_limit', 'slow_query_log_use_global_control',
    ];

    /** Largest value of each integer type, signed and unsigned. */
    private const array INTEGER_MAX = [
        'tinyint' => [127, 255],
        'smallint' => [32_767, 65_535],
        'mediumint' => [8_388_607, 16_777_215],
        'int' => [2_147_483_647, 4_294_967_295],
        'bigint' => [9_223_372_036_854_775_807, 18_446_744_073_709_551_615],
    ];

    /**
     * @param array<string, string>           $status    SHOW GLOBAL STATUS
     * @param array<string, string>           $variables SHOW GLOBAL VARIABLES
     * @param list<array<string, mixed>>|null $replica   SHOW REPLICA STATUS, null when not readable
     *
     * @return array{gauges: array<string, int|float|null>, counters: array<string, int>, config: array<string, string>, replica: list<array<string, mixed>>, findings: list<Finding>}
     */
    public function server(array $status, array $variables, ?array $replica): array
    {
        $int = static fn (string $name): int => (int) ($status[$name] ?? 0);
        $ratio = static fn (int|float $part, int|float $total): ?float => $total > 0 ? round($part / $total, 4) : null;

        $maxConnections = (int) ($variables['max_connections'] ?? 0);
        $readRequests = $int('Innodb_buffer_pool_read_requests');
        $tmpTables = $int('Created_tmp_tables');
        $cacheLookups = $int('Table_open_cache_hits') + $int('Table_open_cache_misses');
        $pagesTotal = $int('Innodb_buffer_pool_pages_total');

        $gauges = [
            'uptime' => $int('Uptime'),
            'threads_connected' => $int('Threads_connected'),
            'threads_running' => $int('Threads_running'),
            'max_connections' => $maxConnections,
            'max_used_connections' => $int('Max_used_connections'),
            'connections_usage' => $ratio($int('Threads_connected'), $maxConnections),
            'max_used_usage' => $ratio($int('Max_used_connections'), $maxConnections),
            'aborted_connects' => $int('Aborted_connects'),
            'aborted_ratio' => $ratio($int('Aborted_connects'), $int('Connections')),
            'buffer_pool_bytes' => (int) ($variables['innodb_buffer_pool_size'] ?? 0),
            'buffer_pool_usage' => $pagesTotal > 0 ? round(1 - $int('Innodb_buffer_pool_pages_free') / $pagesTotal, 4) : null,
            'buffer_pool_dirty' => $ratio($int('Innodb_buffer_pool_pages_dirty'), $pagesTotal),
            'buffer_pool_hit_ratio' => $readRequests > 0 ? round(1 - $int('Innodb_buffer_pool_reads') / $readRequests, 4) : null,
            'tmp_disk_ratio' => $ratio($int('Created_tmp_disk_tables'), $tmpTables),
            'table_cache_hit_ratio' => $ratio($int('Table_open_cache_hits'), $cacheLookups),
            'row_lock_waits' => $int('Innodb_row_lock_waits'),
            'row_lock_time_avg_ms' => $int('Innodb_row_lock_time_avg'),
            'slow_queries' => $int('Slow_queries'),
            'select_full_join' => $int('Select_full_join'),
            'questions_per_second_avg' => $int('Uptime') > 0 ? round($int('Questions') / $int('Uptime'), 2) : null,
        ];

        $counters = [];
        foreach (self::RATE_COUNTERS as $name) {
            $counters[$name] = $int($name);
        }

        $config = [];
        foreach (self::CONFIG_VARIABLES as $name) {
            if (isset($variables[$name])) {
                $config[$name] = $variables[$name];
            }
        }

        $findings = [];
        if (null !== $gauges['connections_usage'] && $gauges['connections_usage'] >= 0.9) {
            $findings[] = $this->finding(SeverityEnum::DANGER, \sprintf('Connessioni al %d%% di max_connections (%d/%d): i nuovi client verranno rifiutati.', (int) round($gauges['connections_usage'] * 100), $gauges['threads_connected'], $maxConnections));
        } elseif (null !== $gauges['max_used_usage'] && $gauges['max_used_usage'] >= 0.8) {
            $findings[] = $this->finding(SeverityEnum::WARNING, \sprintf("Picco di connessioni al %d%% di max_connections (%d/%d) dall'avvio.", (int) round($gauges['max_used_usage'] * 100), $gauges['max_used_connections'], $maxConnections));
        }

        if ($readRequests >= 10_000 && null !== $gauges['buffer_pool_hit_ratio'] && $gauges['buffer_pool_hit_ratio'] < 0.99) {
            $findings[] = $this->finding(SeverityEnum::WARNING, \sprintf('Hit ratio del buffer pool InnoDB al %s%%: molte letture vanno su disco, valutare innodb_buffer_pool_size.', $this->percent($gauges['buffer_pool_hit_ratio'])));
        }

        if ($tmpTables >= 1_000 && null !== $gauges['tmp_disk_ratio'] && $gauges['tmp_disk_ratio'] > 0.25) {
            $findings[] = $this->finding(SeverityEnum::WARNING, \sprintf('Il %s%% delle tabelle temporanee finisce su disco: query con GROUP BY/ORDER BY pesanti o tmp_table_size troppo basso.', $this->percent($gauges['tmp_disk_ratio'])));
        }

        if ($int('Connections') >= 1_000 && null !== $gauges['aborted_ratio'] && $gauges['aborted_ratio'] > 0.05) {
            $findings[] = $this->finding(SeverityEnum::WARNING, \sprintf('%d tentativi di connessione falliti (%s%%): credenziali errate, client che vanno in timeout o tentativi di accesso.', $gauges['aborted_connects'], $this->percent($gauges['aborted_ratio'])));
        }

        if ($cacheLookups >= 1_000 && null !== $gauges['table_cache_hit_ratio'] && $gauges['table_cache_hit_ratio'] < 0.9) {
            $findings[] = $this->finding(SeverityEnum::INFO, \sprintf('Hit ratio della table open cache al %s%%: valutare table_open_cache.', $this->percent($gauges['table_cache_hit_ratio'])));
        }

        if ($gauges['row_lock_waits'] >= 10 && $gauges['row_lock_time_avg_ms'] >= 1_000) {
            $findings[] = $this->finding(SeverityEnum::WARNING, \sprintf('Attesa media sui lock di riga di %d ms (%d attese): transazioni lunghe o in conflitto.', $gauges['row_lock_time_avg_ms'], $gauges['row_lock_waits']));
        }

        if ('OFF' === ($variables['slow_query_log'] ?? null)) {
            $findings[] = $this->finding(SeverityEnum::INFO, 'slow_query_log disattivato: le query lente non vengono registrate su file (restano le statistiche di performance_schema).');
        }

        if ('OFF' === ($variables['performance_schema'] ?? null)) {
            $findings[] = $this->finding(SeverityEnum::INFO, 'performance_schema disattivato: query più pesanti e latenze per tabella non sono disponibili.');
        }

        if (isset($variables['innodb_flush_log_at_trx_commit']) && '1' !== $variables['innodb_flush_log_at_trx_commit']) {
            $findings[] = $this->finding(SeverityEnum::INFO, \sprintf("innodb_flush_log_at_trx_commit = %s: durabilità ridotta, un crash può perdere l'ultimo secondo di transazioni.", $variables['innodb_flush_log_at_trx_commit']));
        }

        if ('ON' === ($variables['read_only'] ?? null) || 'ON' === ($variables['super_read_only'] ?? null)) {
            $findings[] = $this->finding(SeverityEnum::INFO, 'Server in sola lettura (read_only).');
        }

        foreach ($replica ?? [] as $channel) {
            $io = $this->text($channel, 'Replica_IO_Running');
            $sql = $this->text($channel, 'Replica_SQL_Running');
            $lag = $channel['Seconds_Behind_Source'] ?? null;
            $name = '' !== $this->text($channel, 'Channel_Name') ? ' (canale ' . $this->text($channel, 'Channel_Name') . ')' : '';
            if ('Yes' !== $io || 'Yes' !== $sql) {
                $error = trim($this->text($channel, 'Last_IO_Error') . ' ' . $this->text($channel, 'Last_SQL_Error'));
                $findings[] = $this->finding(SeverityEnum::DANGER, \sprintf('Replica ferma%s: IO %s, SQL %s.%s', $name, $io ?: '?', $sql ?: '?', '' !== $error ? ' ' . $error : ''));
            } elseif (is_numeric($lag) && (int) $lag > 300) {
                $findings[] = $this->finding(SeverityEnum::WARNING, \sprintf('Replica in ritardo di %d secondi%s.', (int) $lag, $name));
            }
        }

        return ['gauges' => $gauges, 'counters' => $counters, 'config' => $config, 'replica' => $replica ?? [], 'findings' => $findings];
    }

    /**
     * @param list<array<string, mixed>>      $tables        MetricsRepository::tableStatistics()
     * @param list<string>                    $withoutPk     tables without a primary key
     * @param list<array<string, mixed>>      $autoIncrement MetricsRepository::autoIncrementColumns()
     * @param array<string, mixed>            $defaults      MetricsRepository::schemaDefaults()
     * @param list<array<string, mixed>>|null $unused        unused indexes, null when not readable
     * @param list<array<string, mixed>>|null $redundant     redundant indexes, null when not readable
     *
     * @return array{summary: array<string, mixed>, auto_increment: list<array<string, mixed>>, fragmented: list<array<string, mixed>>, findings: list<Finding>}
     */
    public function schema(array $tables, array $withoutPk, array $autoIncrement, array $defaults, ?array $unused, ?array $redundant): array
    {
        $int = static fn (array $row, string $key): int => is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;
        $collation = $this->text($defaults, 'collation');

        $engines = [];
        $otherCollation = [];
        $fragmented = [];
        $totals = ['rows' => 0, 'data_bytes' => 0, 'index_bytes' => 0, 'free_bytes' => 0];
        foreach ($tables as $table) {
            $name = $this->text($table, 'table_name');
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $int($table, 'rows' === $key ? 'table_rows' : $key);
            }

            $engine = $this->text($table, 'engine');
            $engines[$engine] = ($engines[$engine] ?? 0) + 1;
            if ('' !== $collation && null !== ($table['collation'] ?? null) && $table['collation'] !== $collation) {
                $otherCollation[] = $name;
            }

            $free = $int($table, 'free_bytes');
            $allocated = $int($table, 'data_bytes') + $int($table, 'index_bytes') + $free;
            if ($free >= 64 * 1_048_576 && $allocated > 0 && $free / $allocated >= 0.2) {
                $fragmented[] = ['table_name' => $name, 'free_bytes' => $free, 'free_ratio' => round($free / $allocated, 4)];
            }
        }

        $capacity = [];
        foreach ($autoIncrement as $column) {
            $max = $this->integerMax($this->text($column, 'data_type'), $this->text($column, 'column_type'));
            // NULL for a table that never received a row: the next value is 1.
            $next = max(1, $int($column, 'next_value'));
            if (null === $max) {
                continue;
            }

            $capacity[] = [
                'table_name' => $this->text($column, 'table_name'),
                'column_name' => $this->text($column, 'column_name'),
                'column_type' => $this->text($column, 'column_type'),
                'next_value' => $next,
                'max_value' => $max,
                'usage' => round(($next - 1) / $max, 4),
            ];
        }

        usort($capacity, static fn (array $a, array $b): int => $b['usage'] <=> $a['usage']);

        $findings = [];
        if ([] === $tables) {
            $findings[] = $this->finding(SeverityEnum::INFO, "Nessuna tabella nello schema (o nessuna visibile con i privilegi dell'account).");
        }

        foreach ($capacity as $column) {
            if ($column['usage'] >= 0.75) {
                $findings[] = $this->finding($column['usage'] >= 0.9 ? SeverityEnum::DANGER : SeverityEnum::WARNING, \sprintf(
                    "AUTO_INCREMENT di %s.%s (%s) al %s%% del massimo: all'esaurimento gli INSERT falliscono, valutare un tipo più grande.",
                    $column['table_name'], $column['column_name'], $column['column_type'], $this->percent($column['usage']),
                ));
            }
        }

        if ([] !== $withoutPk) {
            $findings[] = $this->finding(SeverityEnum::WARNING, \sprintf('%d tabell%s senza primary key (%s): InnoDB usa una chiave nascosta, le repliche row-based e Group Replication ne soffrono.', \count($withoutPk), 1 === \count($withoutPk) ? 'a' : 'e', $this->names($withoutPk)));
        }

        $myisam = array_values(array_filter(array_keys($engines), static fn (string $engine): bool => 'MyISAM' === $engine));
        if ([] !== $myisam) {
            $findings[] = $this->finding(SeverityEnum::WARNING, \sprintf('%d tabelle MyISAM: niente transazioni né crash recovery, lock a livello di tabella.', $engines['MyISAM']));
        }

        if ([] !== $fragmented) {
            $findings[] = $this->finding(SeverityEnum::INFO, \sprintf('%d tabell%s con molto spazio libero non restituito (%s): OPTIMIZE TABLE lo recupera.', \count($fragmented), 1 === \count($fragmented) ? 'a' : 'e', $this->names(array_column($fragmented, 'table_name'))));
        }

        if ([] !== $otherCollation) {
            $findings[] = $this->finding(SeverityEnum::INFO, \sprintf('%d tabell%s con collation diversa da quella dello schema (%s): confronti e JOIN fra colonne possono richiedere conversioni.', \count($otherCollation), 1 === \count($otherCollation) ? 'a' : 'e', $collation));
        }

        if (null !== $redundant && [] !== $redundant) {
            $findings[] = $this->finding(SeverityEnum::WARNING, \sprintf('%d indic%s ridondant%s: rallentano le scritture senza aiutare le letture (vedi gli statement DROP INDEX).', \count($redundant), 1 === \count($redundant) ? 'e' : 'i', 1 === \count($redundant) ? 'e' : 'i'));
        }

        if (null !== $unused && [] !== $unused) {
            $findings[] = $this->finding(SeverityEnum::INFO, \sprintf("%d indic%s mai usat%s dall'avvio del server: verificare prima di eliminarli (statistiche azzerate al riavvio).", \count($unused), 1 === \count($unused) ? 'e' : 'i', 1 === \count($unused) ? 'o' : 'i'));
        }

        return [
            'summary' => [
                'charset' => $this->text($defaults, 'charset'),
                'collation' => $collation,
                'table_count' => \count($tables),
                ...$totals,
                'engines' => $engines,
            ],
            'auto_increment' => $capacity,
            'fragmented' => $fragmented,
            'findings' => $findings,
        ];
    }

    /**
     * Worst severity of a list of findings (OK when empty).
     *
     * @param list<Finding> $findings
     */
    public static function worst(array $findings): SeverityEnum
    {
        $worst = SeverityEnum::OK;
        foreach ($findings as $finding) {
            if ($finding['severity']->weight() > $worst->weight()) {
                $worst = $finding['severity'];
            }
        }

        return $worst;
    }

    /**
     * A scalar value of a row as a string ('' when missing or not scalar).
     *
     * @param array<array-key, mixed> $row
     */
    private function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }

    private function integerMax(string $dataType, string $columnType): int|float|null
    {
        $limits = self::INTEGER_MAX[strtolower($dataType)] ?? null;
        if (null === $limits) {
            return null;
        }

        return str_contains(strtolower($columnType), 'unsigned') ? $limits[1] : $limits[0];
    }

    /**
     * @param list<string> $names
     */
    private function names(array $names): string
    {
        return \count($names) > 5 ? implode(', ', \array_slice($names, 0, 5)) . ', …' : implode(', ', $names);
    }

    private function percent(float $ratio): string
    {
        return number_format($ratio * 100, 1, ',', '');
    }

    /**
     * @return Finding
     */
    private function finding(SeverityEnum $severity, string $message): array
    {
        return ['severity' => $severity, 'message' => $message];
    }
}
