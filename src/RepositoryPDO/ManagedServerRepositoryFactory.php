<?php

declare(strict_types=1);

namespace App\RepositoryPDO;

use App\Entity\SqlClient;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierServiceInterface;

/**
 * Builds the PDO repositories of a managed server: they depend on a SqlClient known only at runtime,
 * so they cannot be services themselves. The repositories of one SqlClient share its connection.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @see \App\Tests\Integration\RepositoryPDO\ManagedServerRepositoryFactoryTest
 */
final readonly class ManagedServerRepositoryFactory
{
    public function __construct(
        private EffectivePrimitiveTypeIdentifierServiceInterface $epti,
    ) {
    }

    public function schema(SqlClient $sqlClient): DatabaseSchemaRepository
    {
        return new DatabaseSchemaRepository($sqlClient, $this->epti);
    }

    public function accounts(SqlClient $sqlClient): MysqlAccountRepository
    {
        return new MysqlAccountRepository($sqlClient, $this->epti);
    }

    public function monitor(SqlClient $sqlClient): ServerMonitorRepository
    {
        return new ServerMonitorRepository($sqlClient, $this->epti);
    }

    public function demoData(SqlClient $sqlClient): DemoDataRepository
    {
        return new DemoDataRepository($sqlClient, $this->epti);
    }
}
