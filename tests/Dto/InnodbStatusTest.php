<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\InnodbStatus;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see InnodbStatus}.
 */
final class InnodbStatusTest extends TestCase
{
    private const array RAW_LINES = [
        '=====================================',
        '2024-04-01 12:34:56 0x7f1a2b3c4d5e INNODB MONITOR OUTPUT',
        '=====================================',
        'Per second averages calculated from the last 8 seconds',
        '-----------------',
        'BACKGROUND THREAD',
        '-----------------',
        'srv_master_thread loops: 1 srv_active, 0 srv_shutdown, 100 srv_idle',
        '-----------',
        'SEMAPHORES',
        '-----------',
        'OS WAIT ARRAY INFO: reservation count 24',
        '------------',
        'TRANSACTIONS',
        '------------',
        'Trx id counter 12345',
        '--------',
        'FILE I/O',
        '--------',
        'Pending normal aio reads: 0',
    ];

    private function rawStatus(): string
    {
        return implode("\n", self::RAW_LINES);
    }

    public function testConstructorStoresValues(): void
    {
        $generatedAt = new \DateTimeImmutable('2024-04-01 12:34:56');
        $status = new InnodbStatus($generatedAt, 'raw', ['FOO' => 'bar']);

        $this->assertSame($generatedAt, $status->generatedAt);
        $this->assertSame('raw', $status->rawStatus);
        $this->assertSame(['FOO' => 'bar'], $status->sections);
    }

    public function testFromArrayParsesSectionsFromUppercaseStatusKey(): void
    {
        $status = InnodbStatus::fromArray(['Status' => $this->rawStatus()]);

        $this->assertSame(
            'srv_master_thread loops: 1 srv_active, 0 srv_shutdown, 100 srv_idle',
            $status->getSection('BACKGROUND THREAD')
        );
        $this->assertSame('OS WAIT ARRAY INFO: reservation count 24', $status->getSection('SEMAPHORES'));
        $this->assertSame('Trx id counter 12345', $status->getSection('TRANSACTIONS'));
        $this->assertSame('Pending normal aio reads: 0', $status->getSection('FILE I/O'));
    }

    public function testFromArrayAlsoAcceptsLowercaseStatusKey(): void
    {
        $status = InnodbStatus::fromArray(['status' => $this->rawStatus()]);

        $this->assertSame($this->rawStatus(), $status->rawStatus);
        $this->assertArrayHasKey('TRANSACTIONS', $status->sections);
    }

    public function testFromArrayWithoutAStatusKeyProducesEmptyReport(): void
    {
        $status = InnodbStatus::fromArray(['something' => 'else']);

        $this->assertSame('', $status->rawStatus);
        $this->assertSame([], $status->sections);
        $this->assertNull($status->generatedAt);
    }

    public function testGeneratedAtIsExtractedFromTheHeader(): void
    {
        $status = InnodbStatus::fromArray(['Status' => $this->rawStatus()]);

        $this->assertInstanceOf(\DateTimeImmutable::class, $status->generatedAt);
        $this->assertSame('2024-04-01 12:34:56', $status->generatedAt->format('Y-m-d H:i:s'));
    }

    public function testGetSectionIsCaseInsensitiveAndTrims(): void
    {
        $status = InnodbStatus::fromArray(['Status' => $this->rawStatus()]);

        $this->assertSame(
            $status->getSection('BACKGROUND THREAD'),
            $status->getSection('  background thread  ')
        );
    }

    public function testGetSectionReturnsNullForUnknownSection(): void
    {
        $status = InnodbStatus::fromArray(['Status' => $this->rawStatus()]);

        $this->assertNull($status->getSection('LATCHES'));
    }
}
