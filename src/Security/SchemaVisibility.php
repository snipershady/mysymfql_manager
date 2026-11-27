<?php

declare(strict_types=1);

namespace App\Security;

use App\Dto\ProcessList;
use App\Entity\AppUser;
use App\Entity\SqlClient;
use App\Repository\DatabaseOwnerRepository;
use App\RepositoryPDO\DatabaseSchemaRepository;

/**
 * What a panel user may see or act on inside a server they can access: admins see everything,
 * other users only what belongs to the databases they own on that server.
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
    public function databasesWithStats(AppUser $user, SqlClient $sqlClient): array
    {
        $databases = new DatabaseSchemaRepository($sqlClient)->getDatabasesWithStats();
        if ($this->access->isAdmin()) {
            return $databases;
        }

        $owned = $this->databaseOwnerRepository->findDbNamesOwnedOn($user, $sqlClient);

        return array_values(array_filter($databases, static fn (array $db): bool => \in_array($db['db_name'], $owned, strict: true)));
    }

    /**
     * Server threads: all of them for admins, otherwise only those whose current schema the user owns.
     *
     * @return list<ProcessList>
     */
    public function processes(AppUser $user, SqlClient $sqlClient, DatabaseSchemaRepository $repo): array
    {
        $processes = $repo->showProcessList();
        if ($this->access->isAdmin()) {
            return $processes;
        }

        $owned = $this->databaseOwnerRepository->findDbNamesOwnedOn($user, $sqlClient);

        return array_values(array_filter(
            $processes,
            static fn (ProcessList $p): bool => null !== $p->db && \in_array($p->db, $owned, strict: true)
        ));
    }

    /**
     * Admins manage every MySQL account; other users only those confined to their databases
     * (see {@see MysqlAccountPolicy}).
     */
    public function canManageAccount(AppUser $user, SqlClient $sqlClient, DatabaseSchemaRepository $repo, string $username, string $host): bool
    {
        if ($this->access->isAdmin()) {
            return true;
        }

        return MysqlAccountPolicy::isManageableByOwner(
            panelAccount: (string) $sqlClient->getUsername(),
            username: $username,
            hasGlobalPrivileges: $repo->hasGlobalPrivileges($username, $host),
            grantedDbs: array_column($repo->getUserGrantsByDb($username, $host), 'db'),
            ownedDbs: $this->databaseOwnerRepository->findDbNamesOwnedOn($user, $sqlClient),
        );
    }
}
