<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
use App\Exception\RepositoryException;
use App\Repository\DatabaseOwnerRepository;
use App\Repository\SqlClientRepository;
use App\RepositoryPDO\ManagedServerRepositoryFactory;
use App\Security\Grants\ServerGrantChecker;
use App\Security\Grants\ServerGrantReport;
use Psr\Log\LoggerInterface;

/**
 * Grant check of the registered servers: reads the grants of each server account (SHOW GRANTS, no privilege
 * needed) and compares them with the databases assigned in the catalog (ServerGrantChecker).
 *
 * Panel role: ROLE_ADMIN (page) or CLI. MySQL privileges: none. Read only, on the server and on the catalog.
 * A server that cannot be reached gets a report with the error (logged), the others are still checked.
 *
 * @see \App\Tests\Functional\ServerGrantAuditTest
 */
final readonly class ServerGrantAudit
{
    public function __construct(
        private ManagedServerRepositoryFactory $repositories,
        private SqlClientRepository $sqlClientRepository,
        private DatabaseOwnerRepository $databaseOwnerRepository,
        private ServerGrantChecker $checker,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<ServerGrantReport> one report per registered server, by name
     */
    public function checkAll(): array
    {
        return array_map($this->check(...), $this->sqlClientRepository->findBy([], ['name' => 'ASC']));
    }

    public function check(SqlClient $sqlClient): ServerGrantReport
    {
        try {
            $grants = $this->repositories->accounts($sqlClient)->currentAccountGrants();
        } catch (RepositoryException|\PDOException $exception) {
            $error = $exception instanceof RepositoryException ? $exception->mysqlError() : $exception->getMessage();
            $this->logger->error('Server grant check failed: {error}', ['error' => $error, 'server' => $sqlClient->getName()]);

            return $this->checker->unreachable($sqlClient, $error);
        }

        /** @var list<DatabaseOwner> $assignments */
        $assignments = $this->databaseOwnerRepository->findBy(['sqlClient' => $sqlClient]);

        return $this->checker->check($sqlClient, $grants['account'], $grants['grants'], $assignments);
    }
}
