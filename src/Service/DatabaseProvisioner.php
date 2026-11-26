<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AppUser;
use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
use App\Enum\CharsetEnum;
use App\Enum\CollationEnum;
use App\Exception\ProvisioningConflictException;
use App\Exception\ProvisioningException;
use App\Repository\DatabaseOwnerRepository;
use App\RepositoryPDO\DatabaseSchemaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Operations that touch both the managed MySQL server and the application catalog.
 *
 * Remote DDL and the Doctrine catalog cannot share a transaction, so every operation here
 * defines its order of steps and how to undo the completed ones when a later step fails
 * (compensation, executed in reverse order). Compensation errors are logged and reported in
 * {@see ProvisioningException::$compensationFailures}: they require manual cleanup.
 *
 * Panel role/ownership is checked by the caller; MySQL privileges required on the SqlClient
 * account: CREATE, DROP, CREATE USER and GRANT OPTION on the target database.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @see \App\Tests\Service\DatabaseProvisionerTest
 */
final readonly class DatabaseProvisioner
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DatabaseOwnerRepository $databaseOwnerRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Steps: CREATE DATABASE (without IF NOT EXISTS) → CREATE USER → GRANT → FLUSH → DatabaseOwner.
     * On failure: DROP USER (if created), DROP DATABASE (if created).
     *
     * @param string $privileges a clause already normalized by DatabasePrivilegeEnum::toGrantClause()
     *
     * @throws ProvisioningConflictException when the database or the account already exists (nothing changed)
     * @throws ProvisioningException         when a step fails (completed steps are compensated)
     */
    public function createDatabaseWithOwner(
        DatabaseSchemaRepository $repo,
        SqlClient $sqlClient,
        AppUser $owner,
        string $dbName,
        CharsetEnum $charset,
        CollationEnum $collation,
        string $username,
        #[\SensitiveParameter] string $password,
        string $host,
        string $privileges,
    ): void {
        $this->assertDatabaseIsNew($repo, $dbName);
        $this->assertAccountIsNew($repo, $username, $host);

        /** @var list<array{string, callable(): mixed}> $undo */
        $undo = [];
        try {
            $this->step($repo->createDatabase($dbName, $charset, $collation, ifNotExists: false), 'create database');
            $undo[] = ['drop database ' . $dbName, static fn (): bool => $repo->dropDatabase($dbName)];

            $this->step($repo->createUser($username, $password, $host), 'create user');
            $undo[] = ['drop user ' . $username . '@' . $host, static fn (): bool => $repo->dropUser($username, $host)];

            $this->step($repo->grantPrivileges($dbName, $username, $privileges, $host), 'grant privileges');
            $repo->flushPrivileges();

            $this->entityManager->persist(new DatabaseOwner()->setDbName($dbName)->setOwner($owner)->setSqlClient($sqlClient));
            $this->entityManager->flush();
        } catch (\Throwable $throwable) {
            throw $this->compensate('Database creation failed.', $throwable, $undo, ['db' => $dbName]);
        }
    }

    /**
     * Steps: CREATE USER → GRANT → FLUSH. On failure: DROP USER (if created).
     *
     * @param string $privileges a clause already normalized by DatabasePrivilegeEnum::toGrantClause()
     *
     * @throws ProvisioningConflictException when the account already exists (nothing changed)
     * @throws ProvisioningException         when a step fails (completed steps are compensated)
     */
    public function createUserWithGrant(
        DatabaseSchemaRepository $repo,
        string $dbName,
        string $username,
        #[\SensitiveParameter] string $password,
        string $host,
        string $privileges,
    ): void {
        $this->assertAccountIsNew($repo, $username, $host);

        /** @var list<array{string, callable(): mixed}> $undo */
        $undo = [];
        try {
            $this->step($repo->createUser($username, $password, $host), 'create user');
            $undo[] = ['drop user ' . $username . '@' . $host, static fn (): bool => $repo->dropUser($username, $host)];

            $this->step($repo->grantPrivileges($dbName, $username, $privileges, $host), 'grant privileges');
            $repo->flushPrivileges();
        } catch (\Throwable $throwable) {
            throw $this->compensate('User creation failed.', $throwable, $undo, ['db' => $dbName]);
        }
    }

    /**
     * Steps, inside one catalog transaction: delete every DatabaseOwner of the schema, then
     * DROP DATABASE as the last step. If the DROP fails the transaction is rolled back, so the
     * catalog is unchanged; ownerships are never left behind for a dropped schema (a schema later
     * re-created with the same name must not be handed back to the previous owners).
     *
     * @throws ProvisioningException when the DROP or the catalog update fails
     */
    public function dropDatabaseAndOwnerships(DatabaseSchemaRepository $repo, SqlClient $sqlClient, string $dbName): void
    {
        try {
            $this->entityManager->wrapInTransaction(function () use ($repo, $sqlClient, $dbName): void {
                $this->databaseOwnerRepository->removeAllFor($sqlClient, $dbName);
                $this->step($repo->dropDatabase($dbName), 'drop database');
            });
        } catch (\Throwable $throwable) {
            $this->logger->error('Database drop failed.', ['db' => $dbName, 'exception' => $throwable]);

            throw new ProvisioningException('Database drop failed: the catalog is unchanged.', $throwable);
        }
    }

    private function assertDatabaseIsNew(DatabaseSchemaRepository $repo, string $dbName): void
    {
        if (\in_array($dbName, $repo->showDatabases(), strict: true)) {
            throw new ProvisioningConflictException(\sprintf('Database "%s" already exists.', $dbName));
        }
    }

    private function assertAccountIsNew(DatabaseSchemaRepository $repo, string $username, string $host): void
    {
        if (\in_array($host, $repo->getHostByUser($username), strict: true)) {
            throw new ProvisioningConflictException(\sprintf("MySQL user '%s'@'%s' already exists.", $username, $host));
        }
    }

    /**
     * Repository methods report some failures as `false` instead of throwing.
     */
    private function step(bool $ok, string $description): void
    {
        if (!$ok) {
            throw new \RuntimeException(\sprintf('Step "%s" returned false.', $description));
        }
    }

    /**
     * Runs the undo steps in reverse order, logs everything and builds the exception for the caller.
     *
     * @param list<array{string, callable(): mixed}> $undo
     * @param array<string, string>                  $context
     */
    private function compensate(string $message, \Throwable $cause, array $undo, array $context): ProvisioningException
    {
        $failures = [];
        foreach (array_reverse($undo) as [$description, $action]) {
            try {
                $action();
            } catch (\Throwable $undoError) {
                $failures[] = $description;
                $this->logger->critical('Compensation step failed: manual cleanup required.', $context + ['step' => $description, 'exception' => $undoError]);
            }
        }

        $this->logger->error($message, $context + ['exception' => $cause, 'compensation_failures' => $failures]);

        return new ProvisioningException(
            [] === $failures
                ? $message . ' All partial changes have been undone.'
                : $message . ' Cleanup incomplete, contact an administrator: ' . implode(', ', $failures) . '.',
            $cause,
            $failures,
        );
    }
}
