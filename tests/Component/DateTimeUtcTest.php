<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Component\DateTimeUtc;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see DateTimeUtc}.
 */
final class DateTimeUtcTest extends TestCase
{
    public function testIsADateTime(): void
    {
        $this->assertInstanceOf(\DateTime::class, new DateTimeUtc());
    }

    public function testAlwaysUsesUtc(): void
    {
        $date = new DateTimeUtc('2024-01-01 00:00:00');

        $this->assertSame('UTC', $date->getTimezone()->getName());
        $this->assertSame('2024-01-01 00:00:00', $date->format('Y-m-d H:i:s'));
    }

    public function testCreateFromFormatReturnsUtcBoundInstance(): void
    {
        $date = DateTimeUtc::createFromFormat('Y-m-d H:i:s', '2024-01-01 12:30:00');

        $this->assertInstanceOf(\DateTime::class, $date);
        $this->assertSame('UTC', $date->getTimezone()->getName());
        $this->assertSame('2024-01-01 12:30:00', $date->format('Y-m-d H:i:s'));
    }

    public function testCreateFromFormatIgnoresAForeignTimezoneAndStaysUtc(): void
    {
        $date = DateTimeUtc::createFromFormat('Y-m-d H:i:s', '2024-01-01 12:30:00', new \DateTimeZone('Europe/Rome'));

        $this->assertInstanceOf(\DateTime::class, $date);
        $this->assertSame('UTC', $date->getTimezone()->getName());
    }

    public function testCreateFromFormatReturnsFalseOnInvalidInput(): void
    {
        $this->assertFalse(DateTimeUtc::createFromFormat('Y-m-d', 'clearly-not-a-date'));
    }
}
