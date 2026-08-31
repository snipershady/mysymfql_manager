<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\CollationEnum;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see CollationEnum}.
 */
final class CollationEnumTest extends TestCase
{
    public function testExposesTheExpectedNumberOfCases(): void
    {
        $this->assertCount(13, CollationEnum::cases());
    }

    public function testFromResolvesTheDefaultCollation(): void
    {
        $this->assertSame(CollationEnum::UTF8MB4_0900_AI_CI, CollationEnum::from('utf8mb4_0900_ai_ci'));
    }

    public function testTryFromReturnsNullForUnknownCollation(): void
    {
        $this->assertNull(CollationEnum::tryFrom('klingon_ci'));
    }

    public function testEveryBackingValueIsANonEmptyLowercaseString(): void
    {
        foreach (CollationEnum::cases() as $case) {
            $this->assertNotSame('', $case->value);
            $this->assertSame(strtolower($case->value), $case->value);
        }
    }

    public function testBackingValuesAreUnique(): void
    {
        $values = array_map(static fn (CollationEnum $c): string => $c->value, CollationEnum::cases());

        $this->assertSame($values, array_values(array_unique($values)));
    }

    public function testEveryCollationBelongsToAKnownCharsetFamily(): void
    {
        foreach (CollationEnum::cases() as $case) {
            $this->assertMatchesRegularExpression(
                '/^(utf8mb4|latin1|ascii)_/',
                $case->value,
                \sprintf('Unexpected collation family for %s', $case->value)
            );
        }
    }
}
