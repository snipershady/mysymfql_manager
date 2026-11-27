<?php

declare(strict_types=1);

namespace App\Service;

use App\RepositoryPDO\MysqlAccountRepository;

/**
 * Changes to MySQL accounts that take more than one statement: drop, replacement of database grants.
 *
 * Panel role/ownership (SchemaVisibility::canManageAccount()/canEditGrants()) is checked by the caller.
 * MySQL privileges on the SqlClient account: CREATE USER (drop), GRANT OPTION and the granted privileges on
 * each database (grants), RELOAD (FLUSH PRIVILEGES). These statements are not transactional: a failure
 * stops at the failing statement and the RepositoryException carries MySQL's native message; the statements
 * already run stay applied (the grant modal reloads the current state afterwards).
 *
 * Creation of an account with its first grant lives in {@see DatabaseProvisioner} (it needs compensation).
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @see \App\Tests\Service\MysqlAccountManagerTest
 */
final readonly class MysqlAccountManager
{
    public function drop(MysqlAccountRepository $accounts, string $username, string $host): bool
    {
        if (!$accounts->dropUser($username, $host)) {
            return false;
        }

        $accounts->flushPrivileges();

        return true;
    }

    /**
     * Revokes every privilege on $revokedDbs, then sets the grants of $grants: the grants of a listed
     * database are replaced, not added to (existing ones are revoked first, only where some exist, since
     * REVOKE fails on a database without grants).
     *
     * @param list<array{string, string}> $grants     [database, privilege clause] pairs, clauses already checked
     *                                                against the whitelist (pairs, not a map: an all-digit
     *                                                database name would become an int key)
     * @param list<string>                $revokedDbs
     */
    public function replaceGrants(MysqlAccountRepository $accounts, string $username, string $host, array $grants, array $revokedDbs): void
    {
        foreach ($revokedDbs as $dbName) {
            $accounts->revokeAllPrivilegesOnDb($dbName, $username, $host);
        }

        $alreadyGranted = array_column($accounts->getUserGrantsByDb($username, $host), 'db');
        foreach ($grants as [$dbName, $privileges]) {
            if (\in_array($dbName, $alreadyGranted, strict: true)) {
                $accounts->revokeAllPrivilegesOnDb($dbName, $username, $host);
            }
            $accounts->grantPrivileges(dbName: $dbName, username: $username, privileges: $privileges, host: $host);
        }

        $accounts->flushPrivileges();
    }
}
