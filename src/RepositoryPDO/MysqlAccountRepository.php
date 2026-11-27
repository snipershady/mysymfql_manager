<?php

declare(strict_types=1);

namespace App\RepositoryPDO;

use App\Dto\MysqlUser;
use App\Enum\DatabasePrivilegeEnum;
use App\Exception\RepositoryException;

/**
 * MySQL accounts and database-level grants of a managed server.
 *
 * Instances come from {@see ManagedServerRepositoryFactory}; MySQL privileges: CREATE USER, GRANT OPTION and
 * SELECT on the mysql system schema (mysql.user, mysql.db, mysql.global_grants, mysql.role_edges).
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
readonly class MysqlAccountRepository extends AbstractManagerRepositoryPDO
{
    public function dropUser(string $username, string $host = '%'): bool
    {
        // Account strings 'user'@'host' in DDL cannot use PDO placeholders — use PDO::quote().
        $query = \sprintf(
            'DROP USER IF EXISTS %s@%s',
            $this->pdo->quote($username),
            $this->pdo->quote($host)
        );
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
    public function getHostByUser(string $username): array
    {
        $query = 'SELECT host FROM mysql.user WHERE user = :user';
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->bindValue(':user', $username);
            $stmt->execute();

            return $this->fetchColumnList($stmt);
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function changeUserPassword(string $username, string $newPassword, string $host = '%'): bool
    {
        $query = \sprintf(
            'ALTER USER %s@%s IDENTIFIED BY %s',
            $this->pdo->quote($username),
            $this->pdo->quote($host),
            $this->pdo->quote($newPassword)
        );
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function createUser(string $username, string $password, string $host = '%'): bool
    {
        // Account strings and IDENTIFIED BY value in DDL cannot use PDO placeholders — use PDO::quote().
        $query = \sprintf(
            'CREATE USER %s@%s IDENTIFIED BY %s',
            $this->pdo->quote($username),
            $this->pdo->quote($host),
            $this->pdo->quote($password)
        );
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * @param string $privileges comma-separated list of {@see DatabasePrivilegeEnum} values
     *
     * @throws \InvalidArgumentException when $privileges contains a non-whitelisted keyword
     * @throws RepositoryException
     */
    public function grantPrivileges(string $dbName, string $username, string $privileges = 'ALL PRIVILEGES', string $host = '%'): bool
    {
        // Identifiers and account strings in DDL cannot use PDO placeholders:
        // privileges are whitelisted through the enum, identifiers quoted.
        $query = \sprintf(
            'GRANT %s ON %s.* TO %s@%s',
            DatabasePrivilegeEnum::toGrantClause($privileges),
            $this->quoteIdentifier($dbName),
            $this->pdo->quote($username),
            $this->pdo->quote($host)
        );
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
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
                $this->fetchAllAssoc($stmt)
            );
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
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
            foreach ($this->fetchAllAssoc($stmt) as $row) {
                $privs = [];
                foreach ($privMap as $col => $priv) {
                    if ('Y' === $this->epti->getStringValueFromArray($col, $row, forceString: true)) {
                        $privs[] = $priv;
                    }
                }
                $result[] = [
                    'db' => $this->epti->getStringValueFromArray('Db', $row, forceString: true),
                    'privileges' => $privs,
                    'all_privileges' => \count($privs) === \count($privMap),
                ];
            }

            return $result;
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * Tells whether a MySQL account holds anything beyond database-level grants:
     * static global privileges (mysql.user *_priv), dynamic privileges (mysql.global_grants)
     * or granted roles (mysql.role_edges). An account that does not exist is reported as
     * privileged, so callers relying on this check fail closed.
     */
    public function hasGlobalPrivileges(string $username, string $host): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM mysql.user WHERE User = :user AND Host = :host');
            $stmt->bindValue(':user', $username);
            $stmt->bindValue(':host', $host);
            $stmt->execute();
            $row = $this->fetchOneAssoc($stmt);
            if ([] === $row) {
                return true;
            }

            foreach ($row as $column => $value) {
                if (str_ends_with((string) $column, '_priv') && 'Y' === $value) {
                    return true;
                }
            }

            $stmt = $this->pdo->prepare(
                'SELECT (SELECT COUNT(*) FROM mysql.global_grants WHERE USER = :user1 AND HOST = :host1)
                      + (SELECT COUNT(*) FROM mysql.role_edges WHERE TO_USER = :user2 AND TO_HOST = :host2) AS extra'
            );
            $stmt->bindValue(':user1', $username);
            $stmt->bindValue(':host1', $host);
            $stmt->bindValue(':user2', $username);
            $stmt->bindValue(':host2', $host);
            $stmt->execute();

            return $this->epti->getIntValueFromArray('extra', $this->fetchOneAssoc($stmt)) > 0;
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function revokeAllPrivilegesOnDb(string $dbName, string $username, string $host = '%'): bool
    {
        $query = \sprintf(
            'REVOKE ALL PRIVILEGES ON %s.* FROM %s@%s',
            $this->quoteIdentifier($dbName),
            $this->pdo->quote($username),
            $this->pdo->quote($host)
        );
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function flushPrivileges(): bool
    {
        $query = 'FLUSH PRIVILEGES';
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }
}
