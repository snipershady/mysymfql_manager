<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\DatabasePrivilegeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabasePrivilegeEnum::class)]
final class DatabasePrivilegeEnumTest extends TestCase
{
    public function testExposesAllPrivilegesPlusSixteenSingleOnes(): void
    {
        $this->assertCount(17, DatabasePrivilegeEnum::cases());
    }

    public function testSinglePrivilegeIsReturnedAsIs(): void
    {
        $this->assertSame('SELECT', DatabasePrivilegeEnum::toGrantClause('SELECT'));
    }

    public function testListIsNormalizedCaseWhitespaceAndDuplicates(): void
    {
        $this->assertSame(
            'SELECT, INSERT, SHOW VIEW',
            DatabasePrivilegeEnum::toGrantClause(' select ,INSERT,  show   view ,Select,')
        );
    }

    public function testAllPrivilegesSupersedesOtherEntries(): void
    {
        $this->assertSame('ALL PRIVILEGES', DatabasePrivilegeEnum::toGrantClause('SELECT,all privileges,INSERT'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPrivilegeLists(): iterable
    {
        yield 'empty' => [''];
        yield 'only separators' => [' , ,'];
        yield 'unknown keyword' => ['SELECT,SUPER'];
        yield 'global privilege' => ['GRANT OPTION'];
        yield 'injection attempt' => ['ALL PRIVILEGES ON *.* TO x@y; --'];
        yield 'injection via second token' => ['SELECT, INSERT ON *.* TO root'];
    }

    #[DataProvider('invalidPrivilegeLists')]
    public function testInvalidListIsRejected(string $privileges): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DatabasePrivilegeEnum::toGrantClause($privileges);
    }
}
