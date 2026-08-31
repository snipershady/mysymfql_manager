<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\ProcessList;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see ProcessList}.
 */
final class ProcessListTest extends TestCase
{
    public function testConstructorStoresValues(): void
    {
        $row = new ProcessList(7, 'root', 'localhost:3306', 'mysql', 'Query', 12, 'executing', 'SELECT 1', 'PRIMARY');

        $this->assertSame(7, $row->id);
        $this->assertSame('root', $row->user);
        $this->assertSame('localhost:3306', $row->host);
        $this->assertSame('mysql', $row->db);
        $this->assertSame('Query', $row->command);
        $this->assertSame(12, $row->time);
        $this->assertSame('executing', $row->state);
        $this->assertSame('SELECT 1', $row->info);
        $this->assertSame('PRIMARY', $row->executionEngine);
    }

    public function testFromArrayMapsUppercaseColumns(): void
    {
        $row = ProcessList::fromArray([
            'ID' => '42',
            'USER' => 'app',
            'HOST' => '10.0.0.5:44000',
            'DB' => 'shop',
            'COMMAND' => 'Sleep',
            'TIME' => '3',
            'STATE' => '',
            'INFO' => null,
            'EXECUTION_ENGINE' => 'PRIMARY',
        ]);

        $this->assertSame(42, $row->id);
        $this->assertSame('app', $row->user);
        $this->assertSame('10.0.0.5:44000', $row->host);
        $this->assertSame('shop', $row->db);
        $this->assertSame('Sleep', $row->command);
        $this->assertSame(3, $row->time);
        $this->assertSame('', $row->state);
        $this->assertNull($row->info);
        $this->assertSame('PRIMARY', $row->executionEngine);
    }

    public function testFromArrayLeavesOptionalColumnsNullWhenAbsent(): void
    {
        $row = ProcessList::fromArray([
            'ID' => 1,
            'USER' => 'event_scheduler',
            'HOST' => 'localhost',
            'COMMAND' => 'Daemon',
            'TIME' => 999,
            'EXECUTION_ENGINE' => 'PRIMARY',
        ]);

        $this->assertNull($row->db);
        $this->assertNull($row->state);
        $this->assertNull($row->info);
    }

    public function testFromArrayTreatsExplicitNullDbAsNull(): void
    {
        $row = ProcessList::fromArray([
            'ID' => 1,
            'USER' => 'app',
            'HOST' => 'localhost',
            'DB' => null,
            'COMMAND' => 'Sleep',
            'TIME' => 0,
            'EXECUTION_ENGINE' => 'PRIMARY',
        ]);

        $this->assertNull($row->db);
    }
}
