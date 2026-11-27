<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\BackupDump;
use App\Service\BackupRotationPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackupRotationPolicy::class)]
#[UsesClass(BackupDump::class)]
final class BackupRotationPolicyTest extends TestCase
{
    private \DateTimeImmutable $now;

    #[\Override]
    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-27 12:00:00');
    }

    public function testDeletesOnlyDumpsOlderThanTheRetentionPeriod(): void
    {
        $dumps = [
            $this->dump(3, 'shop', 'full', ageDays: 40),
            $this->dump(3, 'shop', 'full', ageDays: 31),
            $this->dump(3, 'shop', 'full', ageDays: 29),
            $this->dump(3, 'shop', 'full', ageDays: 1),
        ];

        $expired = new BackupRotationPolicy()->expired($dumps, $this->now, days: 30, keepLast: 0);

        $this->assertSame([$dumps[0], $dumps[1]], $expired, 'oldest first');
    }

    public function testKeepsTheMostRecentDumpsOfASeriesWhateverTheirAge(): void
    {
        // Backups stopped 50 days ago: the last two survive.
        $dumps = [
            $this->dump(3, 'shop', 'full', ageDays: 50),
            $this->dump(3, 'shop', 'full', ageDays: 60),
            $this->dump(3, 'shop', 'full', ageDays: 70),
        ];

        $expired = new BackupRotationPolicy()->expired($dumps, $this->now, days: 30, keepLast: 2);

        $this->assertSame([$dumps[2]], $expired);
    }

    public function testSeriesAreRotatedIndependently(): void
    {
        $dumps = [
            'shop full' => $this->dump(3, 'shop', 'full', ageDays: 90),
            'shop orders' => $this->dump(3, 'shop', 'orders', ageDays: 90),
            'same db, other server' => $this->dump(4, 'shop', 'full', ageDays: 90),
            'shop full, recent' => $this->dump(3, 'shop', 'full', ageDays: 2),
        ];

        $expired = new BackupRotationPolicy()->expired(array_values($dumps), $this->now, days: 30, keepLast: 1);

        // Each old dump is the only one of its series except "shop full", which has a newer dump.
        $this->assertSame([$dumps['shop full']], $expired);
    }

    public function testDatabaseNamesWithUnderscoresDoNotMixSeries(): void
    {
        // "app" table "old_users" vs database "app_old" table "users": same file suffix, different folders.
        $a = $this->dump(1, 'app', 'old_users', ageDays: 90);
        $b = $this->dump(1, 'app_old', 'users', ageDays: 90);

        $this->assertNotSame(BackupRotationPolicy::seriesKey($a), BackupRotationPolicy::seriesKey($b));
        $this->assertSame('1/app/old_users', BackupRotationPolicy::seriesKey($a));
        $this->assertSame([], new BackupRotationPolicy()->expired([$a, $b], $this->now, days: 30, keepLast: 1));
    }

    public function testLegacyDumpsFormSeriesByFileName(): void
    {
        $old = $this->dump(sqlClientId: null, db: 'shop', scope: 'full', ageDays: 90);
        $recent = $this->dump(sqlClientId: null, db: 'shop', scope: 'full', ageDays: 1);
        $other = $this->dump(sqlClientId: null, db: 'blog', scope: 'full', ageDays: 90);

        $this->assertSame('legacy/shop_full', BackupRotationPolicy::seriesKey($old));
        $this->assertSame([$old], new BackupRotationPolicy()->expired([$old, $recent, $other], $this->now, days: 30, keepLast: 1));
    }

    public function testRejectsAnInvalidRetention(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BackupRotationPolicy()->expired([], $this->now, days: 0, keepLast: 1);
    }

    public function testRejectsANegativeKeep(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BackupRotationPolicy()->expired([], $this->now, days: 30, keepLast: -1);
    }

    private function dump(?int $sqlClientId, string $db, string $scope, int $ageDays): BackupDump
    {
        static $sequence = 0;
        $mtime = $this->now->modify(\sprintf('-%d days', $ageDays))->getTimestamp() - ++$sequence;
        $filename = \sprintf('bkp_%s_%s_%s.sql', date('Y-m-d_H-i-s', $mtime), $db, $scope);
        $id = null === $sqlClientId ? $filename : \sprintf('%d/%s/%s', $sqlClientId, $db, $filename);

        return new BackupDump(
            filename: $filename,
            path: '/backups/' . $id,
            size: 100,
            mtime: $mtime,
            id: $id,
            sqlClientId: $sqlClientId,
            dbName: null === $sqlClientId ? null : $db,
        );
    }
}
