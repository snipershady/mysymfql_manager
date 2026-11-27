<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\SqlClient;
use App\Repository\SqlClientRepository;
use App\Security\Voter\DatabaseVoter;
use App\Security\Voter\SqlClientVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Single entry point used by controllers to resolve a server (by name) the current user
 * is allowed to work on. Unknown and forbidden servers/databases are indistinguishable
 * (both null), so callers answer with the same 404 and never touch the remote server.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @see \App\Tests\Security\SqlClientAccessResolverTest
 */
final readonly class SqlClientAccessResolver
{
    public function __construct(
        private SqlClientRepository $sqlClientRepository,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * Server-level access: admin or server owner.
     */
    public function server(string $name): ?SqlClient
    {
        $sqlClient = '' === $name ? null : $this->sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient || !$this->authorizationChecker->isGranted(SqlClientVoter::VIEW, $sqlClient)) {
            return null;
        }

        return $sqlClient;
    }

    /**
     * Database-level access: admin, or server owner who also owns $dbName on that server.
     */
    public function database(string $name, string $dbName): ?SqlClient
    {
        $sqlClient = $this->server($name);
        if (!$sqlClient instanceof SqlClient
            || !$this->authorizationChecker->isGranted(DatabaseVoter::ACCESS, new ManagedDatabase($sqlClient, $dbName))) {
            return null;
        }

        return $sqlClient;
    }

    /**
     * Pages that work both per server and per database: database-level access when $dbName is given.
     */
    public function serverOrDatabase(string $name, ?string $dbName): ?SqlClient
    {
        return null === $dbName || '' === $dbName ? $this->server($name) : $this->database($name, $dbName);
    }

    public function isAdmin(): bool
    {
        return $this->authorizationChecker->isGranted('ROLE_ADMIN');
    }
}
