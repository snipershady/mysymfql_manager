<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AppUser;
use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see DatabaseOwner}.
 */
final class DatabaseOwnerTest extends TestCase
{
    public function testDefaults(): void
    {
        $link = new DatabaseOwner();

        $this->assertNull($link->getId());
        $this->assertNull($link->getDbName());
        $this->assertNull($link->getOwner());
        $this->assertNull($link->getSqlClient());
    }

    public function testAccessorsRoundTrip(): void
    {
        $owner = new AppUser()->setUsername('alice')->setEmail('alice@example.com');
        $client = new SqlClient()->setName('primary')->setHost('db');

        $link = new DatabaseOwner()
            ->setDbName('analytics')
            ->setOwner($owner)
            ->setSqlClient($client);

        $this->assertSame('analytics', $link->getDbName());
        $this->assertSame($owner, $link->getOwner());
        $this->assertSame($client, $link->getSqlClient());
    }

    public function testSettersAreFluent(): void
    {
        $link = new DatabaseOwner();

        $this->assertSame($link, $link->setDbName('x'));
        $this->assertSame($link, $link->setOwner(null));
        $this->assertSame($link, $link->setSqlClient(null));
    }
}
