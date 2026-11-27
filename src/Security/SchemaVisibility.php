<?php

declare(strict_types=1);

namespace App\Security;

use App\Dto\InnodbTransaction;
use App\Dto\MysqlUser;
use App\Dto\ProcessList;
use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Repository\DatabaseOwnerRepository;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\RepositoryPDO\MysqlAccountRepository;
use App\RepositoryPDO\ServerMonitorRepository;

/**
 * What a panel user may see or act on inside a server they can access: admins see everything,
 * other users only what belongs to the databases they own on that server.
 *
 * The repositories are those of $sqlClient (from ManagedServerRepositoryFactory): the caller has already
 * checked that the user can access the server.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @see \App\Tests\Security\SchemaVisibilityTest
 */
final readonly class SchemaVisibility
{
    public function __construct(
        private SqlClientAccessResolver $access,
        private DatabaseOwnerRepository $databaseOwnerRepository,
    ) {
    }

    /**
     * Databases of the server with their statistics, limited to the owned ones for non-admins.
     *
     * @return list<array{db_name: string, table_count: int, size_bytes: int}>
     */
    public function databasesWithStats(AppUser $user, SqlClient $sqlClient, DatabaseSchemaRepository $schema): array
    {
        $databases = $schema->getDatabasesWithStats();
        if ($this->access->isAdmin()) {
            return $databases;
        }

        $owned = $this->ownedDbs($user, $sqlClient);

        return array_values(array_filter($databases, static fn (array $db): bool => \in_array($db['db_name'], $owned, strict: true)));
    }

    /**
     * Server threads: all of them for admins, otherwise only those whose current schema the user owns.
     *
     * @return list<ProcessList>
     */
    public function processes(AppUser $user, SqlClient $sqlClient, ServerMonitorRepository $monitor): array
    {
        $processes = $monitor->showProcessList();
        if ($this->access->isAdmin()) {
            return $processes;
        }

        $owned = $this->ownedDbs($user, $sqlClient);

        return array_values(array_filter(
            $processes,
            static fn (ProcessList $p): bool => null !== $p->db && \in_array($p->db, $owned, strict: true)
        ));
    }

    /**
     * Open InnoDB transactions: all of them for admins, otherwise only those of the visible threads
     * (same rule as {@see processes()}).
     *
     * @return list<InnodbTransaction>
     */
    public function transactions(AppUser $user, SqlClient $sqlClient, ServerMonitorRepository $monitor): array
    {
        $transactions = $monitor->showInnodbTransactions();
        if ($this->access->isAdmin()) {
            return $transactions;
        }

        $visibleThreads = array_map(static fn (ProcessList $p): int => $p->id, $this->processes($user, $sqlClient, $monitor));

        return array_values(array_filter(
            $transactions,
            static fn (InnodbTransaction $t): bool => \in_array($t->threadId, $visibleThreads, strict: true)
        ));
    }

    /**
     * MySQL accounts of the server. Database view ($dbName not empty): non-admins see the accounts granted on
     * that database; server view: non-admins see the accounts they may manage.
     *
     * @return list<MysqlUser>
     */
    public function accounts(AppUser $user, SqlClient $sqlClient, MysqlAccountRepository $accounts, string $dbName): array
    {
        $users = $accounts->listUsers($dbName);
        if ($this->access->isAdmin()) {
            return $users;
        }

        return array_values(array_filter(
            $users,
            fn (MysqlUser $u): bool => '' !== $dbName
                ? $u->hasDbGrant
                : $this->canManageAccount($user, $sqlClient, $accounts, $u->user, $u->host)
        ));
    }

    /**
     * Admins manage every MySQL account; other users only those confined to their databases
     * (see {@see MysqlAccountPolicy}).
     */
    public function canManageAccount(AppUser $user, SqlClient $sqlClient, MysqlAccountRepository $accounts, string $username, string $host): bool
    {
        if ($this->access->isAdmin()) {
            return true;
        }

        return MysqlAccountPolicy::isManageableByOwner(
            panelAccount: (string) $sqlClient->getUsername(),
            username: $username,
            hasGlobalPrivileges: $accounts->hasGlobalPrivileges($username, $host),
            grantedDbs: array_column($accounts->getUserGrantsByDb($username, $host), 'db'),
            ownedDbs: $this->ownedDbs($user, $sqlClient),
        );
    }

    /**
     * Database-level grants of an account: all of them for admins, otherwise those on owned databases.
     *
     * @return list<array{db: string, privileges: list<string>, all_privileges: bool}>
     */
    public function grants(AppUser $user, SqlClient $sqlClient, MysqlAccountRepository $accounts, string $username, string $host): array
    {
        $grants = $accounts->getUserGrantsByDb($username, $host);
        if ($this->access->isAdmin()) {
            return $grants;
        }

        $owned = $this->ownedDbs($user, $sqlClient);

        return array_values(array_filter($grants, static fn (array $g): bool => \in_array($g['db'], $owned, strict: true)));
    }

    /**
     * Whether the user may change the grants of an account on $databases: admins always; other users only
     * on databases they own, and only for an account they may manage.
     *
     * @param list<string> $databases every database whose grants would be added, replaced or revoked
     */
    public function canEditGrants(AppUser $user, SqlClient $sqlClient, MysqlAccountRepository $accounts, string $username, string $host, array $databases): bool
    {
        if ($this->access->isAdmin()) {
            return true;
        }

        $owned = $this->ownedDbs($user, $sqlClient);

        return array_all($databases, static fn (string $db): bool => \in_array($db, $owned, strict: true))
            && $this->canManageAccount($user, $sqlClient, $accounts, $username, $host);
    }

    /**
     * @return list<string>
     */
    private function ownedDbs(AppUser $user, SqlClient $sqlClient): array
    {
        return $this->databaseOwnerRepository->findDbNamesOwnedOn($user, $sqlClient);
    }
}
