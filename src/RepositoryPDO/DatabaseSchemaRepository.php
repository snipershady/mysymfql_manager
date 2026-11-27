<?php

declare(strict_types=1);

namespace App\RepositoryPDO;

use App\Enum\CharsetEnum;
use App\Enum\CollationEnum;
use App\Exception\RepositoryException;

/**
 * Databases, tables and SQL console of a managed MySQL server.
 *
 * Instances come from {@see ManagedServerRepositoryFactory}; MySQL privileges depend on the method
 * (SHOW DATABASES, CREATE/DROP on the target schema, the privileges of the statements run by runQuery()).
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
readonly class DatabaseSchemaRepository extends AbstractManagerRepositoryPDO
{
    /**
     * @return list<string>
     */
    public function showDatabases(): array
    {
        $query = 'SHOW DATABASES';
        try {
            $stmt = $this->pdo->prepare($query);

            $stmt->execute();

            return $this->fetchColumnList($stmt);
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getVersion(): array
    {
        $query = 'SELECT @@version';
        try {
            $stmt = $this->pdo->prepare($query);

            $stmt->execute();

            return $this->fetchOneAssoc($stmt);
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function useDbName(string $dbName): bool
    {
        $query = \sprintf('USE %s', $this->quoteIdentifier($dbName));
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function optimizeTable(string $table): bool
    {
        $query = \sprintf('OPTIMIZE TABLE %s', $this->quoteIdentifier($table));
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function optimizeWithAlterTable(string $table): bool
    {
        $query = \sprintf('ALTER TABLE %s ENGINE=INNODB;', $this->quoteIdentifier($table));
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function analyzeTable(string $table): bool
    {
        $query = \sprintf('ANALYZE TABLE %s;', $this->quoteIdentifier($table));
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * @return list<string>
     */
    public function showTables(): array
    {
        $query = 'SHOW TABLES';
        try {
            $stmt = $this->pdo->prepare($query);

            $stmt->execute();

            return $this->fetchColumnList($stmt);
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function showCreateTable(string $table): array
    {
        $query = \sprintf('SHOW CREATE TABLE %s;', $this->quoteIdentifier($table));
        try {
            $stmt = $this->pdo->prepare($query);

            $stmt->execute();

            return $this->fetchAllAssoc($stmt);
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * @return list<array{nome_tabella: string, engine: string, collation: string, record: int, dimensione: float, empty_space: float, auto_increment: int|null, create_time: string|null, update_time: string|null, commento: string}>
     *
     * @throws RepositoryException
     */
    public function showTablesWithStats(): array
    {
        $query = "SELECT
                    TABLE_NAME        AS 'nome_tabella',
                    ENGINE            AS 'engine',
                    TABLE_COLLATION   AS 'collation',
                    TABLE_ROWS        AS 'record',
                    ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS 'dimensione',
                    ROUND(DATA_FREE   / 1024 / 1024, 2) AS 'empty_space',
                    AUTO_INCREMENT    AS 'auto_increment',
                    DATE_FORMAT(CREATE_TIME, '%d/%m/%Y %H:%i') AS 'create_time',
                    DATE_FORMAT(UPDATE_TIME, '%d/%m/%Y %H:%i') AS 'update_time',
                    TABLE_COMMENT     AS 'commento'
                FROM
                    information_schema.TABLES
                WHERE
                    TABLE_SCHEMA = DATABASE()
                    AND TABLE_TYPE = 'BASE TABLE'
                ORDER BY
                    (DATA_LENGTH + INDEX_LENGTH) DESC;";

        try {
            $stmt = $this->pdo->prepare($query);

            $stmt->execute();

            return array_map(fn (array $row): array => [
                'nome_tabella' => $this->epti->getStringValueFromArray('nome_tabella', $row, forceString: true),
                'engine' => $this->epti->getStringValueFromArray('engine', $row, forceString: true),
                'collation' => $this->epti->getStringValueFromArray('collation', $row, forceString: true),
                'record' => $this->epti->getIntValueFromArray('record', $row),
                'dimensione' => $this->epti->getFloatValueFromArray('dimensione', $row),
                'empty_space' => $this->epti->getFloatValueFromArray('empty_space', $row),
                'auto_increment' => isset($row['auto_increment']) ? $this->epti->getIntValueFromArray('auto_increment', $row) : null,
                'create_time' => isset($row['create_time']) ? $this->epti->getStringValueFromArray('create_time', $row, forceString: true) : null,
                'update_time' => isset($row['update_time']) ? $this->epti->getStringValueFromArray('update_time', $row, forceString: true) : null,
                'commento' => $this->epti->getStringValueFromArray('commento', $row, forceString: true),
            ], $this->fetchAllAssoc($stmt));
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function countTableRow(string $table): int
    {
        $query = \sprintf('SELECT COUNT(*) as table_row FROM %s', $this->quoteIdentifier($table));
        try {
            $stmt = $this->pdo->prepare($query);

            $stmt->execute();

            return $this->epti->getIntValueFromArray('table_row', $this->fetchOneAssoc($stmt));
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * @param bool $ifNotExists false makes the statement fail when the schema already exists: callers that
     *                          compensate with DROP DATABASE must use it, so they never drop someone else's schema
     */
    public function createDatabase(string $dbName, CharsetEnum $charset = CharsetEnum::UTF8MB4, CollationEnum $collate = CollationEnum::UTF8MB4_0900_AI_CI, bool $ifNotExists = true): bool
    {
        // PDO placeholders cannot be used for identifiers or DDL keywords:
        // use quoteIdentifier() for the db name and interpolate enum values directly (trusted).
        $query = \sprintf(
            'CREATE DATABASE %s%s CHARACTER SET %s COLLATE %s',
            $ifNotExists ? 'IF NOT EXISTS ' : '',
            $this->quoteIdentifier($dbName),
            $charset->value,
            $collate->value
        );
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function dropDatabase(string $dbName): bool
    {
        // Identifier cannot be a PDO placeholder — use quoteIdentifier().
        $query = \sprintf('DROP DATABASE IF EXISTS %s', $this->quoteIdentifier($dbName));
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function dropTable(string $table): bool
    {
        $query = \sprintf(
            'DROP TABLE IF EXISTS %s',
            $this->quoteIdentifier($table)
        );
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function emptyTable(string $table): bool
    {
        $query = \sprintf(
            'DELETE FROM %s WHERE 1 = 1;',
            $this->quoteIdentifier($table)
        );

        $queryResetAutoIncrement = \sprintf(
            'ALTER TABLE %s AUTO_INCREMENT = 1',
            $this->quoteIdentifier($table)
        );
        try {
            $stmt = $this->pdo->prepare($query);
            $stmtAlter = $this->pdo->prepare($queryResetAutoIncrement);

            return $stmt->execute() && $stmtAlter->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * @return list<array{db_name: string, table_count: int, size_bytes: int}>
     */
    public function getDatabasesWithStats(?string $dbName = null): array
    {
        $andWhere = '';
        if (null !== $dbName) {
            $andWhere = ' AND s.SCHEMA_NAME = :db_name';
        }

        $query = 'SELECT
                      s.SCHEMA_NAME AS db_name,
                      COUNT(t.TABLE_NAME) AS table_count,
                      COALESCE(SUM(t.DATA_LENGTH + t.INDEX_LENGTH), 0) AS size_bytes
                  FROM information_schema.SCHEMATA s
                  LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = s.SCHEMA_NAME
                  WHERE s.SCHEMA_NAME NOT IN (:information_schema, :mysql, :performance_schema, :sys)
                  ' . $andWhere . '
                  GROUP BY s.SCHEMA_NAME
                  ORDER BY s.SCHEMA_NAME';

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':information_schema', 'information_schema');
            $stmt->bindValue(':mysql', 'mysql');
            $stmt->bindValue(':performance_schema', 'performance_schema');
            $stmt->bindValue(':sys', 'sys');
            if (null !== $dbName) {
                $stmt->bindValue(':db_name', $dbName);
            }
            $stmt->execute();

            return array_map(fn (array $row): array => [
                'db_name' => $this->epti->getStringValueFromArray('db_name', $row, forceString: true),
                'table_count' => $this->epti->getIntValueFromArray('table_count', $row),
                'size_bytes' => $this->epti->getIntValueFromArray('size_bytes', $row),
            ], $this->fetchAllAssoc($stmt));
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * Returns, for every base table of the given database, the ordered list of its column names.
     * Used to feed the SQL editor's autocomplete (CodeMirror sql-hint) with schema info.
     *
     * @return array<string, list<string>>
     */
    public function getColumnsBySchema(string $dbName): array
    {
        $query = 'SELECT TABLE_NAME, COLUMN_NAME
                  FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = :db_name
                  ORDER BY TABLE_NAME, ORDINAL_POSITION';

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':db_name', $dbName);
            $stmt->execute();

            $tables = [];
            foreach ($this->fetchAllAssoc($stmt) as $row) {
                $tableName = $this->epti->getStringValueFromArray('TABLE_NAME', $row, forceString: true);
                $tables[$tableName][] = $this->epti->getStringValueFromArray('COLUMN_NAME', $row, forceString: true);
            }

            return $tables;
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * Executes an arbitrary single SQL statement typed by the user in the query console.
     * SELECT-like statements return their result set; other statements (INSERT/UPDATE/
     * DELETE/DDL) return the number of affected rows.
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, affected_rows: int, is_select: bool}
     */
    public function runQuery(string $sql): array
    {
        try {
            $stmt = $this->pdo->query($sql);

            if (false === $stmt || 0 === $stmt->columnCount()) {
                return [
                    'columns' => [],
                    'rows' => [],
                    'affected_rows' => false === $stmt ? 0 : $stmt->rowCount(),
                    'is_select' => false,
                ];
            }

            $rows = $this->fetchAllAssoc($stmt);

            return [
                'columns' => [] === $rows ? [] : array_keys($rows[0]),
                'rows' => $rows,
                'affected_rows' => \count($rows),
                'is_select' => true,
            ];
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . ': ' . $pdoException->getMessage(), 0, $pdoException);
        }
    }
}
