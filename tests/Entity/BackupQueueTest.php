<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AppUser;
use App\Entity\BackupQueue;
use App\Entity\SqlClient;
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
        $this->assertFalse($queue->isDequeued());
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

    public function testDequeueLifecycle(): void
    {
        $queue = new BackupQueue();
        $completedAt = new \DateTime('2024-05-01 08:00:00');

        $queue->setIsDequeued(true)->setCompletedDate($completedAt);

        $this->assertTrue($queue->isDequeued());
        $this->assertSame($completedAt, $queue->getCompletedDate());
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
        $queue = new BackupQueue()->setTable('orders')->setTable(null);

        $this->assertNull($queue->getTable());
    }
}
