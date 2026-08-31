<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AppUser;
use App\Entity\SqlClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see SqlClient}.
 */
final class SqlClientTest extends TestCase
{
    public function testDefaults(): void
    {
        $client = new SqlClient();

        $this->assertNull($client->getId());
        $this->assertSame(3306, $client->getPort());
        $this->assertCount(0, $client->getOwner());
    }

    public function testAccessorsRoundTrip(): void
    {
        $client = new SqlClient()
            ->setName('primary')
            ->setHost('db.internal')
            ->setUsername('root')
            ->setPassword('s3cr3t')
            ->setPort(3307);

        $this->assertSame('primary', $client->getName());
        $this->assertSame('db.internal', $client->getHost());
        $this->assertSame('root', $client->getUsername());
        $this->assertSame('s3cr3t', $client->getPassword());
        $this->assertSame(3307, $client->getPort());
    }

    public function testSetPortFallsBackToTheDefaultWhenGivenNull(): void
    {
        $client = new SqlClient()->setPort(9999)->setPort(null);

        $this->assertSame(3306, $client->getPort());
    }

    public function testToStringCombinesNameAndHost(): void
    {
        $client = new SqlClient()->setName('primary')->setHost('db.internal');

        $this->assertSame('primary@db.internal', (string) $client);
    }

    public function testOwnersAreAddedWithoutDuplicates(): void
    {
        $client = new SqlClient();
        $owner = new AppUser()->setUsername('alice')->setEmail('alice@example.com');

        $client->addOwner($owner);
        $client->addOwner($owner);

        $this->assertCount(1, $client->getOwner());
        $this->assertTrue($client->getOwner()->contains($owner));
    }

    public function testOwnersCanBeRemoved(): void
    {
        $client = new SqlClient();
        $owner = new AppUser()->setUsername('bob')->setEmail('bob@example.com');

        $client->addOwner($owner);
        $client->removeOwner($owner);

        $this->assertCount(0, $client->getOwner());
    }
}
