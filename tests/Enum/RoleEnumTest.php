<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\RoleEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see RoleEnum}.
 */
final class RoleEnumTest extends TestCase
{
    public function testBackingValuesMatchNames(): void
    {
        $this->assertSame('ROLE_DISABLED', RoleEnum::ROLE_DISABLED->value);
        $this->assertSame('ROLE_USER', RoleEnum::ROLE_USER->value);
        $this->assertSame('ROLE_ADMIN', RoleEnum::ROLE_ADMIN->value);
    }

    public function testFromResolvesEveryCase(): void
    {
        $this->assertSame(RoleEnum::ROLE_DISABLED, RoleEnum::from('ROLE_DISABLED'));
        $this->assertSame(RoleEnum::ROLE_USER, RoleEnum::from('ROLE_USER'));
        $this->assertSame(RoleEnum::ROLE_ADMIN, RoleEnum::from('ROLE_ADMIN'));
    }

    public function testTryFromReturnsNullForUnknownValue(): void
    {
        $this->assertNull(RoleEnum::tryFrom('ROLE_SUPERHERO'));
    }

    public function testFromThrowsForUnknownValue(): void
    {
        $this->expectException(\ValueError::class);
        RoleEnum::from('ROLE_SUPERHERO');
    }

    /**
     * @return iterable<string, array{RoleEnum, string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'disabled' => [RoleEnum::ROLE_DISABLED, 'Disabled'];
        yield 'user' => [RoleEnum::ROLE_USER, 'User'];
        yield 'admin' => [RoleEnum::ROLE_ADMIN, 'Administrator'];
    }

    #[DataProvider('labelProvider')]
    public function testLabel(RoleEnum $role, string $expected): void
    {
        $this->assertSame($expected, $role->label());
    }

    public function testEveryCaseHasANonEmptyLabel(): void
    {
        foreach (RoleEnum::cases() as $case) {
            $this->assertNotSame('', $case->label());
        }
    }
}
