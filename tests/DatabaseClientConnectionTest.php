<?php

declare(strict_types=1);

namespace App\Tests;

use App\Component\DatabaseClientConnection;
use App\Entity\SqlClient;
use App\RepositoryPDO\DatabaseSchemaRepository;

/**
 * Integration tests (real MySQL, server TEST_SERVER_NAME) for the per-client connection registry.
 * The second client is an unsaved in-memory copy: nothing is written to the application catalog.
 */
final class DatabaseClientConnectionTest extends MyKernelTestCase
{
    private SqlClient $sqlClient;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        DatabaseClientConnection::reset();

        $sqlClient = $this->entityManager->getRepository(SqlClient::class)->findOneByName($this->serverName);
        $this->assertInstanceOf(SqlClient::class, $sqlClient);
        $this->sqlClient = $sqlClient;
    }

    #[\Override]
    protected function tearDown(): void
    {
        DatabaseClientConnection::reset();
        parent::tearDown();
    }

    public function testSameClientReusesItsConnection(): void
    {
        $this->assertSame(
            DatabaseClientConnection::getInstance($this->sqlClient),
            DatabaseClientConnection::getInstance($this->sqlClient)
        );
    }

    public function testDifferentClientsGetDifferentConnections(): void
    {
        $other = $this->unsavedCopy();

        $this->assertNotSame(
            DatabaseClientConnection::getInstance($this->sqlClient),
            DatabaseClientConnection::getInstance($other)
        );
    }

    public function testUseOnOneClientDoesNotLeakIntoAnother(): void
    {
        new DatabaseSchemaRepository($this->sqlClient)->useDbName('mysql');

        $otherPdo = DatabaseClientConnection::getInstance($this->unsavedCopy());

        $this->assertNull($otherPdo->query('SELECT DATABASE()')?->fetchColumn());
        $this->assertSame('mysql', DatabaseClientConnection::getInstance($this->sqlClient)->query('SELECT DATABASE()')?->fetchColumn());
    }

    public function testChangedCredentialsForceANewConnection(): void
    {
        $copy = $this->unsavedCopy();
        DatabaseClientConnection::getInstance($copy);

        // The cached PDO must not be returned for the new credentials: a real reconnect is attempted and fails.
        $copy->setPassword('definitely-not-the-password-' . bin2hex(random_bytes(4)));
        $this->expectException(\PDOException::class);

        DatabaseClientConnection::getInstance($copy);
    }

    public function testResetDropsOnlyTheGivenClient(): void
    {
        $other = $this->unsavedCopy();
        $pdo = DatabaseClientConnection::getInstance($this->sqlClient);
        $otherPdo = DatabaseClientConnection::getInstance($other);

        DatabaseClientConnection::reset($this->sqlClient);

        $this->assertNotSame($pdo, DatabaseClientConnection::getInstance($this->sqlClient));
        $this->assertSame($otherPdo, DatabaseClientConnection::getInstance($other));
    }

    public function testResetWithoutArgumentDropsEveryConnection(): void
    {
        $pdo = DatabaseClientConnection::getInstance($this->sqlClient);

        DatabaseClientConnection::reset();

        $this->assertNotSame($pdo, DatabaseClientConnection::getInstance($this->sqlClient));
    }

    private function unsavedCopy(): SqlClient
    {
        return new SqlClient()
            ->setName('copy_of_' . $this->sqlClient->getName())
            ->setHost((string) $this->sqlClient->getHost())
            ->setPort($this->sqlClient->getPort())
            ->setUsername((string) $this->sqlClient->getUsername())
            ->setPassword((string) $this->sqlClient->getPassword());
    }
}
