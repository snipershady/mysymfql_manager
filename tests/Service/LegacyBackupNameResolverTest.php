<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LegacyBackupNameResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegacyBackupNameResolver::class)]
final class LegacyBackupNameResolverTest extends TestCase
{
    private const string DATE = 'bkp_2026-04-03_20-55-34_';

    public function testFullDumpOfAKnownDatabase(): void
    {
        $this->assertSame([['db' => 'shop', 'table' => null]], $this->resolve('shop_full', ['shop', 'blog']));
    }

    public function testTableDumpOfAKnownDatabase(): void
    {
        $this->assertSame([['db' => 'shop', 'table' => 'orders']], $this->resolve('shop_orders', ['shop', 'blog']));
    }

    public function testUnknownDatabaseGivesNoReading(): void
    {
        $this->assertSame([], $this->resolve('crm_full', ['shop']));
    }

    public function testInvalidFilenameGivesNoReading(): void
    {
        $this->assertSame([], LegacyBackupNameResolver::resolve('notes.txt', ['shop'], static fn (): bool => true));
        $this->assertSame([], LegacyBackupNameResolver::resolve('bkp_shop_full.sql', ['shop'], static fn (): bool => true));
    }

    public function testPrefixAmbiguityIsSolvedByTheExistingTable(): void
    {
        // "app_old_users" = app + old_users, or app_old + users: only app_old has a "users" table.
        $tables = ['app' => ['accounts'], 'app_old' => ['users']];

        $this->assertSame(
            [['db' => 'app_old', 'table' => 'users']],
            $this->resolve('app_old_users', ['app', 'app_old'], $tables)
        );
    }

    public function testFullDumpWinsOverAMissingTable(): void
    {
        // "app_old_full" = full dump of app_old, or table "old_full" of app (which does not exist).
        $this->assertSame([['db' => 'app_old', 'table' => null]], $this->resolve('app_old_full', ['app', 'app_old'], ['app' => []]));
    }

    public function testStillAmbiguousReadingsAreAllReturned(): void
    {
        $tables = ['app' => ['old_users'], 'app_old' => ['users']];

        $this->assertCount(2, $this->resolve('app_old_users', ['app', 'app_old'], $tables));
    }

    public function testCompressedLegacyDumps(): void
    {
        $this->assertSame([['db' => 'shop', 'table' => null]], LegacyBackupNameResolver::resolve('bkp_2026-01-01_00-00-00_shop_full.sql.zst', ['shop'], static fn (): bool => false));
        $this->assertSame([['db' => 'shop', 'table' => 'orders']], LegacyBackupNameResolver::resolve('bkp_2026-01-01_00-00-00_shop_orders.sql.gz', ['shop'], static fn (): bool => false));
    }

    public function testDuplicateAndInvalidKnownNamesAreIgnored(): void
    {
        $this->assertSame([['db' => 'shop', 'table' => null]], $this->resolve('shop_full', ['shop', 'shop', 'bad-name', '']));
    }

    /**
     * @param list<string>                $dbs
     * @param array<string, list<string>> $tables
     *
     * @return list<array{db: string, table: string|null}>
     */
    private function resolve(string $rest, array $dbs, array $tables = []): array
    {
        return LegacyBackupNameResolver::resolve(
            self::DATE . $rest . '.sql',
            $dbs,
            static fn (string $db, string $table): bool => \in_array($table, $tables[$db] ?? [], strict: true),
        );
    }
}
