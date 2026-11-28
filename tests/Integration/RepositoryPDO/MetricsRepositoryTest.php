<?php

declare(strict_types=1);

namespace App\Tests\Integration\RepositoryPDO;

use App\Component\DatabaseClientConnection;
use App\Entity\SqlClient;
use App\Exception\RepositoryException;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\RepositoryPDO\MetricsRepository;
use App\RepositoryPDO\MysqlAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Cockpit metrics on the real managed server: with the broad test account everything is readable; with an account
 * granted only on one schema the performance_schema/sys sections fail with MySQL's privilege error.
 */
#[CoversClass(MetricsRepository::class)]
final class MetricsRepositoryTest extends KernelTestCase
{
    private SqlClient $sqlClient;

    private DatabaseSchemaRepository $schema;

    private string $db;

    private string $restrictedUser;

    #[\Override]
    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $sqlClient = $entityManager->getRepository(SqlClient::class)->findOneBy(['name' => $_ENV['TEST_SERVER_NAME']]);
        $this->assertInstanceOf(SqlClient::class, $sqlClient);
        $this->sqlClient = $sqlClient;
        $this->db = (string) $_ENV['TEST_DB_NAME_ONE'];
        $this->restrictedUser = 'ut_metrics_' . bin2hex(random_bytes(3));

        $this->schema = new DatabaseSchemaRepository($sqlClient);
        $this->schema->dropDatabase($this->db);
        $this->schema->createDatabase($this->db);
        $this->schema->useDbName($this->db);
        $this->schema->runQuery('CREATE TABLE with_pk (id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, v VARCHAR(10), KEY v (v), KEY v_again (v)) AUTO_INCREMENT = 200');
        $this->schema->runQuery('CREATE TABLE no_pk (v INT)');
        $this->schema->runQuery('SELECT COUNT(*) FROM with_pk');
    }

    protected function tearDown(): void
    {
        new MysqlAccountRepository($this->sqlClient)->dropUser($this->restrictedUser, $this->host());
        $this->schema->dropDatabase($this->db);
        DatabaseClientConnection::reset();
        parent::tearDown();
    }

    public function testServerWideMetrics(): void
    {
        $metrics = new MetricsRepository($this->sqlClient);

        $status = $metrics->globalStatus();
        $variables = $metrics->globalVariables();

        $this->assertArrayHasKey('Uptime', $status);
        $this->assertArrayHasKey('Questions', $status);
        $this->assertArrayHasKey('Innodb_buffer_pool_read_requests', $status);
        $this->assertArrayHasKey('max_connections', $variables);
        $this->assertArrayHasKey('version', $variables);
        $this->assertIsArray($metrics->replicaStatus());
        $this->assertNotSame([], $metrics->statementDigests(schema: null, limit: 5));
    }

    public function testSchemaMetrics(): void
    {
        $metrics = new MetricsRepository($this->sqlClient);

        $this->assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_ai_ci'], $metrics->schemaDefaults($this->db));
        $this->assertSame([], $metrics->schemaDefaults('no_such_schema_xyz'));
        $tables = array_column($metrics->tableStatistics($this->db), 'table_name');
        sort($tables);
        $this->assertSame(['no_pk', 'with_pk'], $tables);
        $this->assertSame(['no_pk'], $metrics->tablesWithoutPrimaryKey($this->db));

        $autoIncrement = $metrics->autoIncrementColumns($this->db);
        $this->assertCount(1, $autoIncrement);
        $this->assertSame('tinyint unsigned', $autoIncrement[0]['column_type']);
        $this->assertSame(200, (int) $autoIncrement[0]['next_value']);

        $this->assertContains('v_again', array_column($metrics->redundantIndexes($this->db), 'index_name'));
        $this->assertIsArray($metrics->unusedIndexes($this->db));
        $this->assertIsArray($metrics->tableIo($this->db));
        $this->assertIsArray($metrics->statementDigests($this->db));
        $this->assertIsArray($metrics->schemaSessions($this->db));
    }

    public function testRestrictedAccountCannotReadPerformanceSchemaNorSys(): void
    {
        $host = $this->host();
        $password = 'Metrics-' . bin2hex(random_bytes(8));
        $accounts = new MysqlAccountRepository($this->sqlClient);
        $accounts->createUser($this->restrictedUser, $password, $host);
        $accounts->grantPrivileges($this->db, $this->restrictedUser, 'ALL PRIVILEGES', $host);

        $restricted = new MetricsRepository(new SqlClient()
            ->setName('restricted')
            ->setHost((string) $this->sqlClient->getHost())
            ->setPort($this->sqlClient->getPort())
            ->setUsername($this->restrictedUser)
            ->setPassword($password));

        // No privilege needed for these.
        $this->assertArrayHasKey('Uptime', $restricted->globalStatus());
        $this->assertSame(['no_pk'], $restricted->tablesWithoutPrimaryKey($this->db));

        $db = $this->db;
        foreach ([
            static fn (): array => $restricted->statementDigests($db),
            static fn (): array => $restricted->redundantIndexes($db),
            $restricted->replicaStatus(...),
        ] as $section) {
            try {
                $section();
                $this->fail('Expected a privilege error.');
            } catch (RepositoryException $repositoryException) {
                $this->assertMatchesRegularExpression('/^ERROR (1142|1227|1044)/', $repositoryException->mysqlError());
            }
        }
    }

    private function host(): string
    {
        return 'localhost' === $_ENV['TEST_SERVER_HOST'] ? 'localhost' : '%';
    }
}
