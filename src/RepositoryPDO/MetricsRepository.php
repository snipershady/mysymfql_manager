<?php

declare(strict_types=1);

namespace App\RepositoryPDO;

use App\Exception\RepositoryException;

/**
 * Read-only metrics of a managed server and of one schema, for the cockpits.
 *
 * MySQL privileges, per method: none for the global status/variables and for information_schema (MySQL shows only
 * the objects the account has privileges on); REPLICATION CLIENT for the replica status; SELECT on
 * performance_schema for statement digests, table I/O and sessions (without PROCESS, sessions of the account
 * only); SELECT on sys (plus performance_schema) for unused and redundant indexes. A missing privilege surfaces as a
 * RepositoryException with MySQL's error (1142, 1227…): the cockpit shows that section as not available.
 *
 * Instances come from {@see ManagedServerRepositoryFactory}.
 *
 * @see \App\Tests\Integration\RepositoryPDO\MetricsRepositoryTest
 */
readonly class MetricsRepository extends AbstractManagerRepositoryPDO
{
    /**
     * SHOW GLOBAL STATUS as name → value.
     *
     * @return array<string, string>
     */
    public function globalStatus(): array
    {
        return $this->nameValue('SHOW GLOBAL STATUS', __METHOD__);
    }

    /**
     * SHOW GLOBAL VARIABLES as name → value.
     *
     * @return array<string, string>
     */
    public function globalVariables(): array
    {
        return $this->nameValue('SHOW GLOBAL VARIABLES', __METHOD__);
    }

    /**
     * SHOW REPLICA STATUS: one row per replication channel, none when the server is not a replica.
     *
     * @return list<array<string, mixed>>
     */
    public function replicaStatus(): array
    {
        return $this->rows('SHOW REPLICA STATUS', [], __METHOD__);
    }

    /**
     * Normalized statements by total execution time (performance_schema), optionally of one schema only.
     * Times in seconds.
     *
     * @return list<array<string, mixed>>
     */
    public function statementDigests(?string $schema, int $limit = 10): array
    {
        $where = null === $schema ? 'WHERE DIGEST IS NOT NULL' : 'WHERE DIGEST IS NOT NULL AND SCHEMA_NAME = :schema';

        return $this->rows(\sprintf(
            'SELECT SCHEMA_NAME AS schema_name, DIGEST_TEXT AS digest_text, COUNT_STAR AS executions,
                    ROUND(SUM_TIMER_WAIT / 1e12, 3) AS total_seconds, ROUND(AVG_TIMER_WAIT / 1e12, 6) AS avg_seconds,
                    SUM_ROWS_EXAMINED AS rows_examined, SUM_ROWS_SENT AS rows_sent, SUM_NO_INDEX_USED AS no_index_used,
                    SUM_CREATED_TMP_DISK_TABLES AS tmp_disk_tables, LAST_SEEN AS last_seen
             FROM performance_schema.events_statements_summary_by_digest
             %s
             ORDER BY SUM_TIMER_WAIT DESC
             LIMIT %d',
            $where,
            max(1, $limit),
        ), null === $schema ? [] : ['schema' => $schema], __METHOD__);
    }

    /**
     * Default charset/collation of a schema; empty when the schema does not exist or is not visible.
     *
     * @return array<string, mixed>
     */
    public function schemaDefaults(string $schema): array
    {
        return $this->rows(
            'SELECT DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation
             FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :schema',
            ['schema' => $schema],
            __METHOD__,
        )[0] ?? [];
    }

    /**
     * Base tables of a schema with their storage statistics (estimates for InnoDB), the biggest first. Fresh values:
     * see freshStatistics().
     *
     * @return list<array<string, mixed>>
     */
    public function tableStatistics(string $schema): array
    {
        $this->freshStatistics();

        return $this->rows(
            "SELECT TABLE_NAME AS table_name, ENGINE AS engine, TABLE_ROWS AS table_rows,
                    DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes, DATA_FREE AS free_bytes,
                    AUTO_INCREMENT AS auto_increment, TABLE_COLLATION AS collation,
                    CREATE_TIME AS created, UPDATE_TIME AS updated
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY DATA_LENGTH + INDEX_LENGTH DESC, TABLE_NAME",
            ['schema' => $schema],
            __METHOD__,
        );
    }

    /**
     * Base tables of a schema without a primary key.
     *
     * @return list<string>
     */
    public function tablesWithoutPrimaryKey(string $schema): array
    {
        $rows = $this->rows(
            "SELECT t.TABLE_NAME AS table_name
             FROM information_schema.TABLES t
             LEFT JOIN information_schema.TABLE_CONSTRAINTS c
                    ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME AND c.CONSTRAINT_TYPE = 'PRIMARY KEY'
             WHERE t.TABLE_SCHEMA = :schema AND t.TABLE_TYPE = 'BASE TABLE' AND c.CONSTRAINT_NAME IS NULL
             ORDER BY t.TABLE_NAME",
            ['schema' => $schema],
            __METHOD__,
        );

        return array_map(fn (array $row): string => $this->epti->getStringValueFromArray('table_name', $row, forceString: true), $rows);
    }

    /**
     * AUTO_INCREMENT columns of a schema with their type and the next value.
     *
     * @return list<array<string, mixed>>
     */
    public function autoIncrementColumns(string $schema): array
    {
        $this->freshStatistics();

        return $this->rows(
            "SELECT c.TABLE_NAME AS table_name, c.COLUMN_NAME AS column_name, c.DATA_TYPE AS data_type,
                    c.COLUMN_TYPE AS column_type, t.AUTO_INCREMENT AS next_value
             FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = :schema AND c.EXTRA LIKE '%auto_increment%'
             ORDER BY c.TABLE_NAME",
            ['schema' => $schema],
            __METHOD__,
        );
    }

    /**
     * Indexes never used since the server started (sys.schema_unused_indexes).
     *
     * @return list<array<string, mixed>>
     */
    public function unusedIndexes(string $schema): array
    {
        return $this->rows(
            'SELECT object_name AS table_name, index_name FROM sys.schema_unused_indexes WHERE object_schema = :schema ORDER BY object_name, index_name',
            ['schema' => $schema],
            __METHOD__,
        );
    }

    /**
     * Indexes made redundant by another one (sys.schema_redundant_indexes), with the DROP INDEX statement.
     *
     * @return list<array<string, mixed>>
     */
    public function redundantIndexes(string $schema): array
    {
        return $this->rows(
            'SELECT table_name, redundant_index_name AS index_name, dominant_index_name AS dominant_index, sql_drop_index
             FROM sys.schema_redundant_indexes WHERE table_schema = :schema ORDER BY table_name, redundant_index_name',
            ['schema' => $schema],
            __METHOD__,
        );
    }

    /**
     * Tables of a schema by total I/O wait time (performance_schema). Times in seconds.
     *
     * @return list<array<string, mixed>>
     */
    public function tableIo(string $schema, int $limit = 10): array
    {
        return $this->rows(\sprintf(
            'SELECT OBJECT_NAME AS table_name, COUNT_READ AS read_count, COUNT_WRITE AS write_count,
                    ROUND(SUM_TIMER_READ / 1e12, 3) AS read_seconds, ROUND(SUM_TIMER_WRITE / 1e12, 3) AS write_seconds
             FROM performance_schema.table_io_waits_summary_by_table
             WHERE OBJECT_SCHEMA = :schema AND COUNT_STAR > 0
             ORDER BY SUM_TIMER_WAIT DESC
             LIMIT %d',
            max(1, $limit),
        ), ['schema' => $schema], __METHOD__);
    }

    /**
     * Sessions whose default schema is $schema (performance_schema.processlist; without the PROCESS privilege
     * only those of the account).
     *
     * @return list<array<string, mixed>>
     */
    public function schemaSessions(string $schema): array
    {
        return $this->rows(
            'SELECT ID AS id, USER AS user, HOST AS host, COMMAND AS command, TIME AS seconds, STATE AS state,
                    LEFT(INFO, 300) AS info
             FROM performance_schema.processlist
             WHERE DB = :schema
             ORDER BY TIME DESC',
            ['schema' => $schema],
            __METHOD__,
        );
    }

    /**
     * Columns of a Percona Server statistics table of information_schema (userstat), or null when the table does
     * not exist (stock MySQL, or a Percona version/configuration without it).
     *
     * @param 'USER_STATISTICS'|'CLIENT_STATISTICS'|'TABLE_STATISTICS'|'INDEX_STATISTICS' $table
     *
     * @return list<string>|null
     */
    public function perconaColumns(string $table): ?array
    {
        $rows = $this->rows(
            "SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = 'information_schema' AND TABLE_NAME = :table ORDER BY ORDINAL_POSITION",
            ['table' => $table],
            __METHOD__,
        );

        return [] === $rows ? null : array_map(fn (array $row): string => $this->epti->getStringValueFromArray('name', $row, forceString: true), $rows);
    }

    /**
     * Rows of a Percona Server statistics table (userstat), optionally of one schema, ordered by the first of
     * $orderBy that exists in $columns (descending). Table and columns come from whitelists, never from input.
     *
     * @param 'USER_STATISTICS'|'CLIENT_STATISTICS'|'TABLE_STATISTICS'|'INDEX_STATISTICS' $table
     * @param list<string>                                                                $columns as returned by perconaColumns()
     * @param list<string>                                                                $orderBy
     *
     * @return list<array<string, mixed>>
     */
    public function perconaStatistics(string $table, array $columns, ?string $schema, array $orderBy, int $limit = 20): array
    {
        $order = array_find($orderBy, static fn (string $column): bool => \in_array($column, $columns, strict: true));
        $where = null !== $schema && \in_array('TABLE_SCHEMA', $columns, strict: true) ? 'WHERE TABLE_SCHEMA = :schema' : '';

        return $this->rows(
            \sprintf('SELECT * FROM information_schema.%s %s %s LIMIT %d', $this->quoteIdentifier($table), $where, null === $order ? '' : 'ORDER BY ' . $this->quoteIdentifier($order) . ' DESC', max(1, $limit)),
            '' === $where ? [] : ['schema' => (string) $schema],
            __METHOD__,
        );
    }

    /**
     * MySQL 8 caches the statistics of information_schema.TABLES (rows, sizes, AUTO_INCREMENT) for
     * information_schema_stats_expiry seconds (default 24 hours): 0 for this session reads them from the storage
     * engine, which a live cockpit needs. Session variable, no privilege required.
     */
    private function freshStatistics(): void
    {
        try {
            $this->pdo->exec('SET SESSION information_schema_stats_expiry = 0');
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * @return array<string, string>
     */
    private function nameValue(string $query, string $method): array
    {
        $values = [];
        foreach ($this->rows($query, [], $method) as $row) {
            $values[$this->epti->getStringValueFromArray('Variable_name', $row, forceString: true)] = $this->epti->getStringValueFromArray('Value', $row, forceString: true);
        }

        return $values;
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $query, array $parameters, string $method): array
    {
        try {
            $stmt = $this->pdo->prepare($query);
            foreach ($parameters as $name => $value) {
                $stmt->bindValue(':' . $name, $value);
            }

            $stmt->execute();

            return $this->fetchAllAssoc($stmt);
        } catch (\PDOException $pdoException) {
            throw new RepositoryException($method . $pdoException->getMessage(), 0, $pdoException);
        }
    }
}
