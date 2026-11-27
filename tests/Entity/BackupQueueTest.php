<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AppUser;
use App\Entity\BackupQueue;
use App\Entity\SqlClient;
use App\Enum\BackupQueueStatusEnum;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see BackupQueue}.
 */
final class BackupQueueTest extends TestCase
{
    public function testDefaults(): void
    {
        $queue = new BackupQueue();

        $this->assertNull($queue->getId());
        $this->assertSame(BackupQueueStatusEnum::PENDING, $queue->getStatus());
        $this->assertSame(0, $queue->getAttempts());
        $this->assertNull($queue->getStartedDate());
        $this->assertNull($queue->getError());
        $this->assertInstanceOf(\DateTime::class, $queue->getRequestDate());
        $this->assertNull($queue->getCompletedDate());
        $this->assertNull($queue->getTable());
    }

    public function testAssociationsRoundTrip(): void
    {
        $client = new SqlClient()->setName('primary')->setHost('db');
        $owner = new AppUser()->setUsername('alice')->setEmail('alice@example.com');

        $queue = new BackupQueue()
            ->setSqlClient($client)
            ->setOwner($owner)
            ->setDbName('shop')
            ->setTable('orders');

        $this->assertSame($client, $queue->getSqlClient());
        $this->assertSame($owner, $queue->getOwner());
        $this->assertSame('shop', $queue->getDbName());
        $this->assertSame('orders', $queue->getTable());
    }

    public function testDoneLifecycle(): void
    {
        $completedAt = new \DateTime('2024-05-01 08:00:00');
        $queue = new BackupQueue()->markFailed('first run failed')->markDone($completedAt);

        $this->assertSame(BackupQueueStatusEnum::DONE, $queue->getStatus());
        $this->assertSame($completedAt, $queue->getCompletedDate());
        $this->assertNull($queue->getError(), 'a successful run clears the previous error');
    }

    public function testFailedRunKeepsTheTrimmedNativeError(): void
    {
        $queue = new BackupQueue()->markFailed("  mysqldump: Got error: 2003: Can't connect\n");

        $this->assertSame(BackupQueueStatusEnum::FAILED, $queue->getStatus());
        $this->assertSame("mysqldump: Got error: 2003: Can't connect", $queue->getError());
        $this->assertNull($queue->getCompletedDate());
        $this->assertSame('Errore sconosciuto.', new BackupQueue()->markFailed(' ')->getError());
    }

    public function testStatusLabelsAndColours(): void
    {
        $this->assertSame(['In attesa', 'In corso', 'Completato', 'Fallito'], array_map(static fn (BackupQueueStatusEnum $s): string => $s->label(), BackupQueueStatusEnum::cases()));
        $this->assertSame(['secondary', 'info', 'success', 'danger'], array_map(static fn (BackupQueueStatusEnum $s): string => $s->color(), BackupQueueStatusEnum::cases()));
    }

    public function testRequestDateCanBeOverridden(): void
    {
        $queue = new BackupQueue();
        $requestedAt = new \DateTime('2024-01-02 03:04:05');

        $queue->setRequestDate($requestedAt);

        $this->assertSame($requestedAt, $queue->getRequestDate());
    }

    public function testTableIsNullable(): void
    {
        $queue = new BackupQueue()->setTable('orders')->setTable(tableName: null);

        $this->assertNull($queue->getTable());
    }
}
