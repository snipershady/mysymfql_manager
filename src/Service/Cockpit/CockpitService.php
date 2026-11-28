<?php

declare(strict_types=1);

namespace App\Service\Cockpit;

use App\Entity\SqlClient;
use App\Enum\SeverityEnum;
use App\Exception\RepositoryException;
use App\RepositoryPDO\ManagedServerRepositoryFactory;
use App\RepositoryPDO\MetricsRepository;
use Psr\Clock\ClockInterface;

/**
 * Data of the two live cockpits, as JSON-ready arrays.
 *
 * - server(): global status and configuration, connections, InnoDB, replication, heaviest statements of the whole
 *   server, size of every database. Panel role: ROLE_ADMIN (checked by the controller: server-wide data shows the
 *   activity of every user and database).
 * - schema(): tables, sizes, missing primary keys, AUTO_INCREMENT capacity, fragmentation, unused and redundant
 *   indexes, table I/O, heaviest statements and sessions of one schema only. Panel role: admin or owner of the
 *   database (checked by the controller).
 *
 * MySQL privileges: none for status, variables and information_schema; the optional sections need SELECT on
 * performance_schema / sys or REPLICATION CLIENT. A section the account cannot read comes back as
 * {available: false, error, grant} (MySQL's error and the GRANT that would enable it) and the rest of the cockpit
 * still works. Read only: nothing is changed on the server.
 *
 * Percona Server: when version_comment says Percona, both cockpits add the userstat statistics (server: per user and
 * per client; schema: per table and per index of that schema only). They are read only if userstat is ON and the
 * table exists in that version; otherwise the section says why and how to enable it (SET PERSIST userstat = ON).
 * The tables are read column-agnostically (SELECT *, known columns shown first): their layout differs across
 * Percona versions.
 *
 * @phpstan-type Section array{available: bool, rows: list<array<string, mixed>>, error: ?string, grant: ?string}
 *
 * @see \App\Tests\Functional\CockpitTest
 */
final readonly class CockpitService
{
    /** Percona userstat tables of the server cockpit, with the columns to order by (first existing one). */
    private const array PERCONA_SERVER_TABLES = [
        'USER_STATISTICS' => ['BUSY_TIME', 'CPU_TIME', 'TOTAL_CONNECTIONS'],
        'CLIENT_STATISTICS' => ['BUSY_TIME', 'CPU_TIME', 'TOTAL_CONNECTIONS'],
    ];

    /** Percona userstat tables of the schema cockpit (filtered on TABLE_SCHEMA). */
    private const array PERCONA_SCHEMA_TABLES = [
        'TABLE_STATISTICS' => ['ROWS_READ', 'ROWS_CHANGED'],
        'INDEX_STATISTICS' => ['ROWS_READ'],
    ];

    public function __construct(
        private ManagedServerRepositoryFactory $repositories,
        private ClockInterface $clock,
        private CockpitAdvisor $advisor = new CockpitAdvisor(),
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RepositoryException when the server cannot be queried at all
     */
    public function server(SqlClient $sqlClient): array
    {
        $metrics = $this->repositories->metrics($sqlClient);
        $monitor = $this->repositories->monitor($sqlClient);
        $account = $this->account($sqlClient);

        $replica = $this->section($metrics->replicaStatus(...), \sprintf('GRANT REPLICATION CLIENT ON *.* TO %s', $account));
        $variables = $metrics->globalVariables();
        $analysis = $this->advisor->server($metrics->globalStatus(), $variables, $replica['available'] ? $replica['rows'] : null);
        $percona = $this->percona($metrics, $variables, schema: null);
        $findings = $analysis['findings'];
        if ($percona['detected'] && 'ON' !== $percona['userstat']) {
            $findings[] = ['severity' => SeverityEnum::INFO, 'message' => 'Percona Server con userstat disattivato: attivandolo (SET PERSIST userstat = ON) il cockpit mostra le statistiche per utente, client, tabella e indice. Overhead ridotto ma non nullo.'];
        }

        return [
            'generated_at' => $this->clock->now()->format(\DATE_ATOM),
            'gauges' => $analysis['gauges'],
            'counters' => $analysis['counters'],
            'config' => $analysis['config'],
            'processes' => [
                'running' => $this->optional($monitor->getRunningProcesses(...)),
                'blocked' => $this->optional($monitor->getBlockedProcesses(...)),
            ],
            'replica' => $replica,
            'top_queries' => $this->section(static fn (): array => $metrics->statementDigests(schema: null, limit: 15), $this->performanceSchemaGrant($account)),
            'databases' => $this->repositories->schema($sqlClient)->getDatabasesWithStats(),
            'percona' => $percona,
            ...$this->health($findings),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RepositoryException when the schema cannot be queried at all
     */
    public function schema(SqlClient $sqlClient, string $dbName): array
    {
        $metrics = $this->repositories->metrics($sqlClient);
        $account = $this->account($sqlClient);
        $sysGrant = \sprintf('GRANT SELECT ON sys.* TO %1$s; %2$s', $account, $this->performanceSchemaGrant($account));

        $tables = $metrics->tableStatistics($dbName);
        $withoutPk = $metrics->tablesWithoutPrimaryKey($dbName);
        $unused = $this->section(static fn (): array => $metrics->unusedIndexes($dbName), $sysGrant);
        $redundant = $this->section(static fn (): array => $metrics->redundantIndexes($dbName), $sysGrant);
        $analysis = $this->advisor->schema(
            $tables,
            $withoutPk,
            $metrics->autoIncrementColumns($dbName),
            $metrics->schemaDefaults($dbName),
            $unused['available'] ? $unused['rows'] : null,
            $redundant['available'] ? $redundant['rows'] : null,
        );

        return [
            'generated_at' => $this->clock->now()->format(\DATE_ATOM),
            'summary' => $analysis['summary'],
            'tables' => $tables,
            'without_primary_key' => $withoutPk,
            'auto_increment' => $analysis['auto_increment'],
            'fragmented' => $analysis['fragmented'],
            'unused_indexes' => $unused,
            'redundant_indexes' => $redundant,
            'table_io' => $this->section(static fn (): array => $metrics->tableIo($dbName), $this->performanceSchemaGrant($account)),
            'top_queries' => $this->section(static fn (): array => $metrics->statementDigests($dbName, 10), $this->performanceSchemaGrant($account)),
            'sessions' => $this->section(static fn (): array => $metrics->schemaSessions($dbName), $this->performanceSchemaGrant($account)),
            'percona' => $this->percona($metrics, $metrics->globalVariables(), $dbName),
            ...$this->health($analysis['findings']),
        ];
    }

    /**
     * Percona Server userstat statistics: server-wide (per user, per client) when $schema is null, otherwise per
     * table and per index of $schema only.
     *
     * @param array<string, string> $variables
     *
     * @return array{detected: bool, version: string, userstat: ?string, sections: array<string, Section>}
     */
    private function percona(MetricsRepository $metrics, array $variables, ?string $schema): array
    {
        $version = $variables['version_comment'] ?? '';
        if (!str_contains($version, 'Percona')) {
            return ['detected' => false, 'version' => $version, 'userstat' => null, 'sections' => []];
        }

        $userstat = $variables['userstat'] ?? null;
        $tables = null === $schema ? self::PERCONA_SERVER_TABLES : self::PERCONA_SCHEMA_TABLES;
        $sections = [];
        foreach ($tables as $table => $orderBy) {
            if ('ON' !== $userstat) {
                $sections[$table] = $this->unavailable('userstat è disattivato: Percona non raccoglie queste statistiche.', 'SET PERSIST userstat = ON');

                continue;
            }

            try {
                $columns = $metrics->perconaColumns($table);
            } catch (RepositoryException $repositoryException) {
                $sections[$table] = $this->unavailable($repositoryException->mysqlError(), grant: null);

                continue;
            }

            $sections[$table] = null === $columns
                ? $this->unavailable(\sprintf('La tabella information_schema.%s non esiste in questa versione di Percona Server.', $table), grant: null)
                : $this->section(static fn (): array => $metrics->perconaStatistics($table, $columns, $schema, $orderBy), '');
        }

        return ['detected' => true, 'version' => $version, 'userstat' => $userstat, 'sections' => $sections];
    }

    /**
     * @return Section
     */
    private function unavailable(string $error, ?string $grant): array
    {
        return ['available' => false, 'rows' => [], 'error' => $error, 'grant' => $grant];
    }

    /**
     * Runs an optional section: its rows, or why it is not available and the GRANT that would enable it.
     *
     * @param callable(): list<array<string, mixed>> $read
     *
     * @return Section
     */
    private function section(callable $read, string $grant): array
    {
        try {
            return ['available' => true, 'rows' => $read(), 'error' => null, 'grant' => null];
        } catch (RepositoryException $repositoryException) {
            return ['available' => false, 'rows' => [], 'error' => $repositoryException->mysqlError(), 'grant' => $grant];
        }
    }

    /**
     * A counter that may need a privilege the account lacks: null instead of failing the whole cockpit.
     *
     * @param callable(): ?int $read
     */
    private function optional(callable $read): ?int
    {
        try {
            return $read();
        } catch (RepositoryException) {
            return null;
        }
    }

    /**
     * @param list<array{severity: SeverityEnum, message: string}> $findings
     *
     * @return array{severity: array{value: string, label: string, color: string}, findings: list<array{severity: string, label: string, color: string, message: string}>}
     */
    private function health(array $findings): array
    {
        $worst = CockpitAdvisor::worst($findings);

        return [
            'severity' => ['value' => $worst->value, 'label' => $worst->label(), 'color' => $worst->color()],
            'findings' => array_map(static fn (array $finding): array => [
                'severity' => $finding['severity']->value,
                'label' => $finding['severity']->label(),
                'color' => $finding['severity']->color(),
                'message' => $finding['message'],
            ], $findings),
        ];
    }

    private function performanceSchemaGrant(string $account): string
    {
        return \sprintf('GRANT SELECT ON performance_schema.* TO %s', $account);
    }

    private function account(SqlClient $sqlClient): string
    {
        return \sprintf("'%s'@'…'", str_replace("'", "''", (string) $sqlClient->getUsername()));
    }
}
