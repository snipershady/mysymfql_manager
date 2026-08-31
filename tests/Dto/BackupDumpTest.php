<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\BackupDump;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see BackupDump}.
 */
final class BackupDumpTest extends TestCase
{
    public function testConstructorStoresValues(): void
    {
        $dump = new BackupDump('bkp.sql', '/var/backups/bkp.sql', 2048, 1_700_000_000);

        $this->assertSame('bkp.sql', $dump->filename);
        $this->assertSame('/var/backups/bkp.sql', $dump->path);
        $this->assertSame(2048, $dump->size);
        $this->assertSame(1_700_000_000, $dump->mtime);
    }

    public function testFromArrayWithNativeTypes(): void
    {
        $dump = BackupDump::fromArray([
            'filename' => 'bkp.sql',
            'path' => '/var/backups/bkp.sql',
            'size' => 2048,
            'mtime' => 1_700_000_000,
        ]);

        $this->assertSame('bkp.sql', $dump->filename);
        $this->assertSame('/var/backups/bkp.sql', $dump->path);
        $this->assertSame(2048, $dump->size);
        $this->assertSame(1_700_000_000, $dump->mtime);
    }

    public function testFromArrayCoercesNumericStrings(): void
    {
        $dump = BackupDump::fromArray([
            'filename' => 'bkp.sql',
            'path' => '/var/backups/bkp.sql',
            'size' => '4096',
            'mtime' => '1700000123',
        ]);

        $this->assertSame(4096, $dump->size);
        $this->assertSame(1_700_000_123, $dump->mtime);
    }

    public function testFromArrayWithMissingKeysFallsBackToDefaults(): void
    {
        $dump = BackupDump::fromArray([]);

        $this->assertSame('', $dump->filename);
        $this->assertSame('', $dump->path);
        $this->assertSame(0, $dump->size);
        $this->assertSame(0, $dump->mtime);
    }

    public function testInstanceIsImmutable(): void
    {
        $dump = new BackupDump('bkp.sql', '/tmp/bkp.sql', 1, 2);

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line -- intentional write to a readonly property
        $dump->size = 99;
    }
}
