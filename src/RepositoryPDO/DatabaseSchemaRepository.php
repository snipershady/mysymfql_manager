<?php

namespace App\RepositoryPDO;

use App\Dto\InnodbStatus;
use App\Dto\MysqlUser;
use App\Dto\ProcessList;
use App\Enum\CharsetEnum;
use App\Enum\CollationEnum;
use App\Exception\RepositoryException;

/**
 * Description of AbstractManagerRepositoryPDO.
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

            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function getVersion(): array
    {
        $query = 'SELECT @@version';
        try {
            $stmt = $this->pdo->prepare($query);

            $stmt->execute();

            return $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function useDbName(string $dbName): bool
    {
        $query = sprintf('USE %s', $this->quoteIdentifier($dbName));
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function optimizeTable(string $table): bool
    {
        $query = sprintf('OPTIMIZE TABLE %s', $this->quoteIdentifier($table));
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function optimizeWithAlterTable(string $table): bool
    {
        $query = sprintf('ALTER TABLE %s ENGINE=INNODB;', $this->quoteIdentifier($table));
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function analyzeTable(string $table): bool
    {
        $query = sprintf('ANALYZE TABLE %s;', $this->quoteIdentifier($table));
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
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

            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }
    
    public function showCreateTable(string $table): array
    {
        $query = sprintf('SHOW CREATE TABLE %s;', $this->quoteIdentifier($table));
        try {
            $stmt = $this->pdo->prepare($query);

            $stmt->execute();

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
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

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function countTableRow(string $table): int
    {
        $query = sprintf('SELECT COUNT(*) as table_row FROM %s', $table);
        try {
            $stmt = $this->pdo->prepare($query);

            $stmt->execute();

            return $stmt->fetch(\PDO::FETCH_ASSOC)['table_row'] ?? 0;
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function createDatabase(string $dbName, CharsetEnum $charset = CharsetEnum::UTF8MB4, CollationEnum $collate = CollationEnum::UTF8MB4_0900_AI_CI): bool
    {
        // PDO placeholders cannot be used for identifiers or DDL keywords:
        // use quoteIdentifier() for the db name and interpolate enum values directly (trusted).
        $query = sprintf(
            'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET %s COLLATE %s',
            $this->quoteIdentifier($dbName),
            $charset->value,
            $collate->value
        );
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function createDummyTable(): bool
    {
        $query = sprintf("CREATE TABLE `jujutsu_kaisen_cast` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `nome` VARCHAR(100) NOT NULL,
                `tecnica` VARCHAR(255) NOT NULL,
                PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=%s COLLATE=%s 
                  COMMENT='Tabella dedicata agli stregoni e maledizioni di JJK';", CharsetEnum::UTF8MB4->value,
            CollationEnum::UTF8MB4_0900_AI_CI->value);
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function populateDummiTable(): bool
    {
        $query = "INSERT INTO `jujutsu_kaisen_cast` (`nome`, `tecnica`) VALUES 
                ('Yuji Itadori', 'Pugno Divergente / Lampo Nero'),
                ('Satoru Gojo', 'Infinito / Sei Occhi'),
                ('Megumi Fushiguro', 'Tecnica delle Dieci Ombre'),
                ('Ryomen Sukuna', 'Reliquiario Demoniaco (Taglio e Squarcio)');";
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function createDummyTableTwo(): bool
    {
        $query = sprintf("CREATE TABLE `jojo_cast` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `nome` VARCHAR(100) NOT NULL COMMENT 'Nome del personaggio (es. Jotaro Kujo)',
                `stand` VARCHAR(100) DEFAULT NULL COMMENT 'Nome dello Stand (es. Star Platinum)',
                PRIMARY KEY (`id`),
                INDEX `idx_stand` (`stand`) 
                ) ENGINE=InnoDB DEFAULT CHARSET=%s COLLATE=%s 
                COMMENT='Tabella personaggi e stand JoJo';", CharsetEnum::UTF8MB4->value,
            CollationEnum::UTF8MB4_0900_AI_CI->value);
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function populateDummiTableTwo(): bool
    {
        $query = "INSERT INTO `jojo_cast` (`nome`, `stand`) VALUES 
                ('Jotaro Kujo', 'Star Platinum'),
                ('Dio Brando', 'The World'),
                ('Josuke Higashikata', 'Crazy Diamond'),
                ('Giorno Giovanna', 'Gold Experience'),
                ('Jolyne Cujoh', 'Stone Free'),
                ('Bruno Bucciarati', 'Sticky Fingers');";
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function dropDatabase(string $dbName): bool
    {
        // Identifier cannot be a PDO placeholder — use quoteIdentifier().
        $query = sprintf('DROP DATABASE IF EXISTS %s', $this->quoteIdentifier($dbName));
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function dropUser(string $username, string $host = '%'): bool
    {
        // Account strings 'user'@'host' in DDL cannot use PDO placeholders — use PDO::quote().
        $query = sprintf(
            'DROP USER IF EXISTS %s@%s',
            $this->pdo->quote($username),
            $this->pdo->quote($host)
        );
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function dropTable(string $table): bool
    {
        $query = sprintf(
            'DROP TABLE IF EXISTS %s',
            $table
        );
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function emptyTable(string $table): bool
    {
        $query = sprintf(
            'DELETE FROM %s WHERE 1 = 1;',
            $table
        );

        $queryResetAutoIncrement = sprintf(
            'ALTER TABLE %s AUTO_INCREMENT = 1',
            $table
        );
        try {
            $stmt = $this->pdo->prepare($query);
            $stmtAlter = $this->pdo->prepare($queryResetAutoIncrement);

            return $stmt->execute() && $stmtAlter->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * @return list<string>
     */
    public function getHostByUser(string $username): array
    {
        $query = 'SELECT host FROM mysql.user WHERE user = :user';
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':user', $username);
            $stmt->execute();

            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function changeUserPassword(string $username, string $newPassword, string $host = '%'): bool
    {
        $query = sprintf(
            'ALTER USER %s@%s IDENTIFIED BY %s',
            $this->pdo->quote($username),
            $this->pdo->quote($host),
            $this->pdo->quote($newPassword)
        );
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function createUser(string $username, string $password, string $host = '%'): bool
    {
        // Account strings and IDENTIFIED BY value in DDL cannot use PDO placeholders — use PDO::quote().
        $query = sprintf(
            'CREATE USER %s@%s IDENTIFIED BY %s',
            $this->pdo->quote($username),
            $this->pdo->quote($host),
            $this->pdo->quote($password)
        );
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function grantPrivileges(string $dbName, string $username, string $privileges = 'ALL PRIVILEGES', string $host = '%'): bool
    {
        // Identifiers and account strings in DDL cannot use PDO placeholders.
        // $privileges is a caller-controlled keyword string (e.g. 'ALL PRIVILEGES', 'SELECT,INSERT').
        $query = sprintf(
            'GRANT %s ON %s.* TO %s@%s',
            $privileges,
            $this->quoteIdentifier($dbName),
            $this->pdo->quote($username),
            $this->pdo->quote($host)
        );
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * Returns all MySQL users on the server, each annotated with whether
     * they hold an explicit database-level grant on $dbName.
     *
     * @return list<MysqlUser>
     */
    public function listUsers(string $dbName = ''): array
    {
        if ('' !== $dbName) {
            $query = "SELECT
                          u.User,
                          u.Host,
                          u.account_locked,
                          CASE WHEN EXISTS (
                              SELECT 1 FROM mysql.db d
                              WHERE d.User = u.User AND d.Host = u.Host AND d.Db = :dbName
                          ) THEN 1 ELSE 0 END AS has_db_grant
                      FROM mysql.user u
                      WHERE u.User != ''
                      AND u.Host != 'localhost'
                      ORDER BY u.User, u.Host";
        } else {
            $query = "SELECT u.User, u.Host, u.account_locked, 0 AS has_db_grant
                      FROM mysql.user u
                      WHERE u.User != ''
                      AND u.Host != 'localhost'
                      ORDER BY u.User, u.Host";
        }

        try {
            $stmt = $this->pdo->prepare($query);
            if ('' !== $dbName) {
                $stmt->bindValue(':dbName', $dbName);
            }

            $stmt->execute();

            return array_map(
                MysqlUser::fromArray(...),
                $stmt->fetchAll(\PDO::FETCH_ASSOC)
            );
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * Returns all databases where the given user has explicit grants in mysql.db,
     * with the list of individual privileges and a flag for ALL PRIVILEGES.
     *
     * @return list<array{db: string, privileges: list<string>, all_privileges: bool}>
     */
    public function getUserGrantsByDb(string $username, string $host): array
    {
        $query = 'SELECT
                      Db,
                      Select_priv, Insert_priv, Update_priv, Delete_priv,
                      Create_priv, Drop_priv, References_priv, Index_priv, Alter_priv,
                      Create_view_priv, Show_view_priv, Create_routine_priv, Alter_routine_priv,
                      Execute_priv, Trigger_priv, Event_priv
                  FROM mysql.db
                  WHERE User = :user AND Host = :host
                  ORDER BY Db';

        $privMap = [
            'Select_priv' => 'SELECT', 'Insert_priv' => 'INSERT',
            'Update_priv' => 'UPDATE', 'Delete_priv' => 'DELETE',
            'Create_priv' => 'CREATE', 'Drop_priv' => 'DROP',
            'References_priv' => 'REFERENCES', 'Index_priv' => 'INDEX',
            'Alter_priv' => 'ALTER', 'Create_view_priv' => 'CREATE VIEW',
            'Show_view_priv' => 'SHOW VIEW', 'Create_routine_priv' => 'CREATE ROUTINE',
            'Alter_routine_priv' => 'ALTER ROUTINE', 'Execute_priv' => 'EXECUTE',
            'Trigger_priv' => 'TRIGGER', 'Event_priv' => 'EVENT',
        ];

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':user', $username);
            $stmt->bindValue(':host', $host);
            $stmt->execute();

            $result = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $privs = [];
                foreach ($privMap as $col => $priv) {
                    if (($row[$col] ?? 'N') === 'Y') {
                        $privs[] = $priv;
                    }
                }
                $result[] = [
                    'db' => $row['Db'],
                    'privileges' => $privs,
                    'all_privileges' => count($privs) === count($privMap),
                ];
            }

            return $result;
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function revokeAllPrivilegesOnDb(string $dbName, string $username, string $host = '%'): bool
    {
        $query = sprintf(
            'REVOKE ALL PRIVILEGES ON %s.* FROM %s@%s',
            $this->quoteIdentifier($dbName),
            $this->pdo->quote($username),
            $this->pdo->quote($host)
        );
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function flushPrivileges(): bool
    {
        $query = 'FLUSH PRIVILEGES';
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function killProcessById(int $processId): bool
    {
        $query = sprintf('KILL %s', $processId);
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function showEngineInnodbStatus(): InnodbStatus
    {
        $query = 'SHOW ENGINE INNODB STATUS';
        try {
            $stmt = $this->pdo->prepare($query);

            $stmt->execute();

            return InnodbStatus::fromArray($stmt->fetch(\PDO::FETCH_ASSOC));
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * <p>
     * -- Check if the db is enabled to show processes from performance_schema
     * SHOW VARIABLES LIKE 'performance_schema_show_processlist';
     * -- If it is OFF, enable it (requires global privileges)
     * SET GLOBAL performance_schema_show_processlist = ON;
     * </p>.
     *
     * @return array<ProcessList>
     *
     * @throws RepositoryException
     */
    public function showProcessList(): array
    {
        $query = 'SELECT * FROM performance_schema.processlist;';
        try {
            $stmt = $this->pdo->prepare($query);

            $stmt->execute();

            $resultSet = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $result = [];
            foreach ($resultSet as $row) {
                $pl = ProcessList::fromArray($row);
                $result[] = $pl;
            }

            return $result;
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function getActiveConnections(): int
    {
        $query = "SHOW STATUS LIKE 'Threads_connected'";
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return (int) ($row['Value'] ?? 0);
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function getRunningProcesses(): int
    {
        $query = "SELECT COUNT(*) as cnt FROM performance_schema.processlist WHERE COMMAND != 'Sleep'";
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();

            return (int) ($stmt->fetch(\PDO::FETCH_ASSOC)['cnt'] ?? 0);
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function getBlockedProcesses(): int
    {
        $query = "SELECT COUNT(*) as cnt FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'";
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();

            return (int) ($stmt->fetch(\PDO::FETCH_ASSOC)['cnt'] ?? 0);
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
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
                  '.$andWhere.'
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

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__.$pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * Escapes a MySQL identifier (database name, table name, etc.) by wrapping
     * it in backticks and doubling any backtick present in the value.
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
