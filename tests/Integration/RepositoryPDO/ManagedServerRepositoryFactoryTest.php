<?php

declare(strict_types=1);

namespace App\Tests\Integration\RepositoryPDO;

use App\Component\DatabaseClientConnection;
use App\Entity\SqlClient;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\RepositoryPDO\DemoDataRepository;
use App\RepositoryPDO\ManagedServerRepositoryFactory;
use App\RepositoryPDO\MysqlAccountRepository;
use App\RepositoryPDO\ServerMonitorRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The factory from the container, on the real managed server (read-only).
 */
#[CoversClass(ManagedServerRepositoryFactory::class)]
final class ManagedServerRepositoryFactoryTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        DatabaseClientConnection::reset();
        parent::tearDown();
    }

    public function testRepositoriesOfOneServerShareItsConnection(): void
    {
        $factory = self::getContainer()->get(ManagedServerRepositoryFactory::class);
        $this->assertInstanceOf(ManagedServerRepositoryFactory::class, $factory);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $sqlClient = $entityManager->getRepository(SqlClient::class)->findOneBy(['name' => $_ENV['TEST_SERVER_NAME']]);
        $this->assertInstanceOf(SqlClient::class, $sqlClient);

        $schema = $factory->schema($sqlClient);
        $this->assertInstanceOf(DatabaseSchemaRepository::class, $schema);
        $this->assertInstanceOf(MysqlAccountRepository::class, $factory->accounts($sqlClient));
        $this->assertInstanceOf(DemoDataRepository::class, $factory->demoData($sqlClient));
        $monitor = $factory->monitor($sqlClient);
        $this->assertInstanceOf(ServerMonitorRepository::class, $monitor);
        $this->assertGreaterThanOrEqual(1, $monitor->getActiveConnections());

        // USE on one repository is seen by another one of the same server: one PDO per SqlClient.
        $schema->useDbName('mysql');
        $this->assertSame([['db' => 'mysql']], $factory->schema($sqlClient)->runQuery('SELECT DATABASE() AS db')['rows']);
    }
}
