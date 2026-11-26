<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Decides whether a non-admin panel user may manage (drop, change password, edit grants)
 * a MySQL account on a server they own.
 *
 * The account must be "contained" in the user's databases: it is not the panel's own
 * connection account nor a reserved system account, it holds no global privileges or roles,
 * and it has at least one database-level grant, all of them on databases the user owns.
 * Wildcard grants (e.g. `app\_%`) never match an owned name, so they are denied.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @see \App\Tests\Security\MysqlAccountPolicyTest
 */
final class MysqlAccountPolicy
{
    private const array RESERVED_ACCOUNTS = ['root', 'mysql.sys', 'mysql.session', 'mysql.infoschema'];

    /**
     * @param list<string> $grantedDbs databases where the account has a mysql.db grant
     * @param list<string> $ownedDbs   databases owned by the panel user on the same server
     */
    public static function isManageableByOwner(
        string $panelAccount,
        string $username,
        bool $hasGlobalPrivileges,
        array $grantedDbs,
        array $ownedDbs,
    ): bool {
        if ('' === $username || $username === $panelAccount || \in_array($username, self::RESERVED_ACCOUNTS, strict: true)) {
            return false;
        }

        if ($hasGlobalPrivileges || [] === $grantedDbs) {
            return false;
        }

        return array_all($grantedDbs, static fn (string $db): bool => \in_array($db, $ownedDbs, strict: true));
    }
}
