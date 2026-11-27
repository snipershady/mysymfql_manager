<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BackupRotateCommand;
use App\Dto\BackupDump;
use App\Service\BackupRotationPolicy;
use App\Service\MysqldumpManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs the command on a temporary BACKUP_PATH with real files (no MySQL server nor client binaries:
 * rotation only reads the listing).
 */
#[CoversClass(BackupRotateCommand::class)]
#[UsesClass(BackupRotationPolicy::class)]
#[UsesClass(MysqldumpManager::class)]
#[UsesClass(BackupDump::class)]
final class BackupRotateCommandTest extends TestCase
{
    private const string NOW = '2026-11-27 12:00:00';

    private string $backupDir;

    #[\Override]
    protected function setUp(): void
    {
        $this->backupDir = sys_get_temp_dir() . '/backup_rotate_test_' . bin2hex(random_bytes(6));
        mkdir($this->backupDir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->backupDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $this->assertInstanceOf(\SplFileInfo::class, $item);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->backupDir);

    }

    public function testWithoutForceNothingIsDeleted(): void
    {
        $old = $this->file('3/shop/bkp_2026-09-01_02-00-00_shop_full.sql', ageDays: 87);
        $this->file('3/shop/bkp_2026-11-26_02-00-00_shop_full.sql', ageDays: 1);

        $tester = $this->rotate(['days' => '30']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('would delete', $tester->getDisplay());
        $this->assertStringContainsString('nothing has been deleted', $tester->getDisplay());
        $this->assertFileExists($old);
    }

    public function testForceDeletesExpiredDumpsAndStalePartials(): void
    {
        $old = $this->file('3/shop/bkp_2026-09-01_02-00-00_shop_full.sql', ageDays: 87);
        $recent = $this->file('3/shop/bkp_2026-11-26_02-00-00_shop_full.sql', ageDays: 1);
        $onlyDump = $this->file('3/blog/bkp_2026-01-01_02-00-00_blog_full.sql', ageDays: 330);
        $legacy = $this->file('bkp_2025-01-01_02-00-00_shop_full.sql', ageDays: 700);
        $legacyRecent = $this->file('bkp_2026-11-20_02-00-00_shop_full.sql', ageDays: 7);
        $stalePart = $this->file('3/shop/bkp_2026-10-01_02-00-00_shop_full.sql.part', ageDays: 57);
        $runningPart = $this->file('3/shop/bkp_2026-11-27_11-59-00_shop_full.sql.part', ageDays: 0);

        $tester = $this->rotate(['days' => '30', '--force' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('3 file(s) deleted', $tester->getDisplay());
        $this->assertFileDoesNotExist($old);
        $this->assertFileDoesNotExist($legacy);
        $this->assertFileDoesNotExist($stalePart);
        $this->assertFileExists($recent);
        $this->assertFileExists($onlyDump, 'the last dump of a series survives (--keep=1)');
        $this->assertFileExists($legacyRecent);
        $this->assertFileExists($runningPart, 'a dump in progress is never touched');
    }

    public function testKeepZeroAlsoDeletesTheLastDumpOfASeries(): void
    {
        $onlyDump = $this->file('3/blog/bkp_2026-01-01_02-00-00_blog_full.sql', ageDays: 330);

        $tester = $this->rotate(['days' => '30', '--keep' => '0', '--force' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertFileDoesNotExist($onlyDump);
    }

    public function testNothingToDelete(): void
    {
        $this->file('3/shop/bkp_2026-11-26_02-00-00_shop_full.sql', ageDays: 1);

        $tester = $this->rotate(['days' => '30', '--force' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No backups older than 30 day(s)', $tester->getDisplay());
    }

    public function testInvalidArgumentsAreRejected(): void
    {
        $old = $this->file('3/shop/bkp_2026-09-01_02-00-00_shop_full.sql', ageDays: 87);

        foreach ([['days' => '0'], ['days' => 'abc'], ['days' => '-5'], ['days' => '30', '--keep' => '-1']] as $arguments) {
            $this->assertSame(Command::INVALID, $this->rotate([...$arguments, '--force' => true])->getStatusCode());
        }

        $this->assertFileExists($old);
    }

    public function testUndeletableFilesMakeTheRunFail(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root ignores directory permissions.');
        }

        $old = $this->file('3/shop/bkp_2026-09-01_02-00-00_shop_full.sql', ageDays: 87);
        $this->file('3/shop/bkp_2026-11-26_02-00-00_shop_full.sql', ageDays: 1);
        chmod(\dirname($old), 0o555);

        try {
            set_error_handler(static fn (): bool => true); // unlink() warning on a read-only directory
            $tester = $this->rotate(['days' => '30', '--force' => true]);
        } finally {
            restore_error_handler();
            chmod(\dirname($old), 0o755);
        }

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('error: cannot delete', $tester->getDisplay());
        $this->assertFileExists($old);
    }

    /**
     * @param array<string, string|bool> $input
     */
    private function rotate(array $input): CommandTester
    {
        $command = new BackupRotateCommand(new MysqldumpManager($this->backupDir), new BackupRotationPolicy(), new MockClock(self::NOW), new NullLogger());
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    private function file(string $relative, int $ageDays): string
    {
        $path = $this->backupDir . '/' . $relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o755, recursive: true);
        }

        file_put_contents($path, '-- dump');
        touch($path, new \DateTimeImmutable(self::NOW)->modify(\sprintf('-%d days', $ageDays))->getTimestamp());

        return $path;
    }
}
