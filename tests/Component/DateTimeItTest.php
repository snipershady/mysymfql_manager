<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Component\DateTimeIt;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see DateTimeIt}.
 */
final class DateTimeItTest extends TestCase
{
    public function testIsADateTime(): void
    {
        $this->assertInstanceOf(\DateTime::class, new DateTimeIt());
    }

    public function testDefaultsToRomeTimezone(): void
    {
        $date = new DateTimeIt('2024-06-01 10:00:00');

        $this->assertSame('Europe/Rome', $date->getTimezone()->getName());
        $this->assertSame('2024-06-01 10:00:00', $date->format('Y-m-d H:i:s'));
    }

    public function testExplicitTimezoneIsHonoured(): void
    {
        $date = new DateTimeIt('2024-06-01 10:00:00', new \DateTimeZone('UTC'));

        $this->assertSame('UTC', $date->getTimezone()->getName());
    }

    public function testCreateFromFormatReturnsRomeBoundInstance(): void
    {
        $date = DateTimeIt::createFromFormat('Y-m-d H:i:s', '2024-06-01 10:00:00');

        $this->assertInstanceOf(\DateTime::class, $date);
        $this->assertSame('Europe/Rome', $date->getTimezone()->getName());
        $this->assertSame('2024-06-01 10:00:00', $date->format('Y-m-d H:i:s'));
    }

    public function testCreateFromFormatHonoursExplicitTimezone(): void
    {
        $date = DateTimeIt::createFromFormat('Y-m-d H:i:s', '2024-06-01 10:00:00', new \DateTimeZone('UTC'));

        $this->assertInstanceOf(\DateTime::class, $date);
        $this->assertSame('UTC', $date->getTimezone()->getName());
    }

    public function testCreateFromFormatReturnsFalseOnInvalidInput(): void
    {
        $this->assertFalse(DateTimeIt::createFromFormat('Y-m-d', 'clearly-not-a-date'));
    }
}
