<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\CharsetEnum;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see CharsetEnum}.
 *
 * The deprecated {@see CharsetEnum::UTF8MB3} case is deliberately never
 * referenced by name so the strict PHPUnit configuration
 * (failOnDeprecation) is not tripped.
 */
final class CharsetEnumTest extends TestCase
{
    public function testExposesTheExpectedNumberOfCases(): void
    {
        $this->assertCount(15, CharsetEnum::cases());
    }

    public function testFromResolvesTheRecommendedCharset(): void
    {
        $this->assertSame(CharsetEnum::UTF8MB4, CharsetEnum::from('utf8mb4'));
    }

    public function testTryFromReturnsNullForUnknownCharset(): void
    {
        $this->assertNull(CharsetEnum::tryFrom('ebcdic'));
    }

    public function testEveryBackingValueIsANonEmptyLowercaseString(): void
    {
        foreach (CharsetEnum::cases() as $case) {
            $this->assertNotSame('', $case->value);
            $this->assertSame(strtolower($case->value), $case->value);
        }
    }

    public function testBackingValuesAreUnique(): void
    {
        $values = array_map(static fn (CharsetEnum $c): string => $c->value, CharsetEnum::cases());

        $this->assertSame($values, array_values(array_unique($values)));
    }

    public function testKnownCharsetsArePresent(): void
    {
        $values = array_map(static fn (CharsetEnum $c): string => $c->value, CharsetEnum::cases());

        foreach (['utf8mb4', 'latin1', 'ascii', 'binary', 'utf16', 'utf32'] as $expected) {
            $this->assertContains($expected, $values);
        }
    }
}
