<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\BackupCompressionEnum;
use App\Service\DumpCompressor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Runs the real gzip/zstd binaries on temporary files (skipped when a binary is missing).
 */
#[CoversClass(DumpCompressor::class)]
#[CoversClass(BackupCompressionEnum::class)]
final class DumpCompressorTest extends TestCase
{
    private const string SQL = "-- dump\nCREATE TABLE t (id INT);\nINSERT INTO t VALUES (1),(2),(3);\n";

    private string $dir;

    /** @var list<string> */
    private array $warnings = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dump_compressor_test_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * @return iterable<string, array{BackupCompressionEnum}>
     */
    public static function formats(): iterable
    {
        yield 'zstd' => [BackupCompressionEnum::ZSTD];
        yield 'gzip' => [BackupCompressionEnum::GZIP];
    }

    #[DataProvider('formats')]
    public function testCompressesInPlaceAndReadsBack(BackupCompressionEnum $format): void
    {
        $this->requireBinary($format);
        $file = $this->part();

        $kept = $this->compressor($format->value)->compress($file);

        $this->assertSame($format, $kept['compression']);
        $this->assertSame($file . $format->extension(), $kept['path']);
        $this->assertNull($kept['warning']);
        $this->assertFileDoesNotExist($file, 'the uncompressed source is removed');
        $this->assertLessThan(\strlen(self::SQL) + 64, (int) filesize($kept['path']));

        // Readers look at the final extension (.sql.zst / .sql.gz), as the listing does.
        $final = $this->dir . '/bkp_2026-11-28_10-00-00_shop_full.sql' . $format->extension();
        rename($kept['path'], $final);

        $this->assertSame(self::SQL, $this->streamed($final));
        $target = $this->dir . '/restore.sql.part';
        $this->assertSame(['ok' => true, 'error' => ''], $this->compressor('none')->decompressTo($final, $target));
        $this->assertSame(self::SQL, file_get_contents($target));
        $this->assertSame(0o600, fileperms($target) & 0o777);
        $this->assertSame([], $this->warnings);
    }

    public function testNoneKeepsTheDumpAsItIs(): void
    {
        $file = $this->part();

        $kept = $this->compressor('none')->compress($file);

        $this->assertSame(['path' => $file, 'compression' => BackupCompressionEnum::NONE, 'warning' => null], $kept);
        $this->assertSame(self::SQL, $this->streamed($file));
    }

    public function testAnInvalidSettingKeepsTheDumpUncompressed(): void
    {
        $file = $this->part();

        $kept = $this->compressor('lz4')->compress($file);

        $this->assertSame(BackupCompressionEnum::NONE, $kept['compression']);
        $this->assertSame($file, $kept['path']);
        $this->assertStringContainsString('BACKUP_COMPRESSION="lz4" is not valid', (string) $kept['warning']);
        $this->assertSame(self::SQL, file_get_contents($file));
        $this->assertCount(1, $this->warnings);
    }

    public function testTheSettingIgnoresCaseAndSpaces(): void
    {
        $this->assertSame(BackupCompressionEnum::ZSTD, $this->compressor(' ZSTD ')->configured());
    }

    public function testAMissingBinaryKeepsTheDumpUncompressed(): void
    {
        $file = $this->part();
        $previousPath = getenv('PATH');
        putenv('PATH=' . $this->dir); // no zstd in there

        try {
            $kept = $this->compressor('zstd')->compress($file);
            $error = $this->compressor('zstd')->stream($this->dir . '/x.sql.zst', static function (): void {
            });
        } finally {
            putenv(false === $previousPath ? 'PATH' : 'PATH=' . $previousPath);
        }

        $this->assertSame(BackupCompressionEnum::NONE, $kept['compression']);
        $this->assertStringContainsString('zstd command was not found', (string) $kept['warning']);
        $this->assertFileExists($file);
        $this->assertStringContainsString('zstd command was not found', (string) $error);
    }

    public function testACorruptedDumpReportsTheToolError(): void
    {
        $this->requireBinary(BackupCompressionEnum::ZSTD);
        $file = $this->dir . '/bkp_2026-11-28_10-00-00_shop_full.sql.zst';
        file_put_contents($file, 'not zstd at all');

        $error = $this->compressor('zstd')->stream($file, static function (): void {
        });
        $target = $this->dir . '/restore.sql.part';
        $result = $this->compressor('zstd')->decompressTo($file, $target);

        $this->assertNotNull($error);
        $this->assertFalse($result['ok']);
        $this->assertNotSame('', $result['error']);
    }

    public function testAFailedCompressionKeepsTheDumpUncompressed(): void
    {
        $this->requireBinary(BackupCompressionEnum::GZIP);
        $file = $this->part();
        file_put_contents($file . '.gz', 'already there'); // gzip refuses to overwrite it

        $kept = $this->compressor('gzip')->compress($file);

        $this->assertSame(BackupCompressionEnum::NONE, $kept['compression']);
        $this->assertStringContainsString('gzip failed', (string) $kept['warning']);
        $this->assertSame(self::SQL, file_get_contents($file));
    }

    public function testUnreadableFiles(): void
    {
        $missing = $this->dir . '/missing.sql';

        $this->assertStringContainsString('not readable', (string) $this->compressor('none')->stream($missing, static function (): void {
        }));
        $this->assertFalse($this->compressor('none')->decompressTo($missing, $this->dir . '/missing-dir/x')['ok']);
    }

    public function testFormatOfAFileName(): void
    {
        $this->assertSame(BackupCompressionEnum::ZSTD, BackupCompressionEnum::fromFilename('bkp_x_full.sql.zst'));
        $this->assertSame(BackupCompressionEnum::GZIP, BackupCompressionEnum::fromFilename('bkp_x_full.sql.gz'));
        $this->assertSame(BackupCompressionEnum::NONE, BackupCompressionEnum::fromFilename('bkp_x_full.sql'));
        $this->assertTrue(BackupCompressionEnum::isDumpFilename('bkp_x_full.sql.zst'));
        $this->assertFalse(BackupCompressionEnum::isDumpFilename('bkp_x_full.sql.part'));
        $this->assertFalse(BackupCompressionEnum::isDumpFilename('bkp_x_full.sql.part.zst'));
        $this->assertFalse(BackupCompressionEnum::isDumpFilename('notes_full.sql'));
        $this->assertSame('bkp_x_full', BackupCompressionEnum::baseName('bkp_x_full.sql.gz'));
        $this->assertSame('readme', BackupCompressionEnum::baseName('readme'));
        $this->assertSame([], BackupCompressionEnum::NONE->compressArguments('f'));
        $this->assertSame([], BackupCompressionEnum::NONE->decompressArguments('f'));
        foreach (BackupCompressionEnum::cases() as $case) {
            $this->assertNotSame('', $case->label());
        }
    }

    private function compressor(string $configured): DumpCompressor
    {
        $warnings = &$this->warnings;
        $logger = new class($warnings) extends AbstractLogger {
            /** @param list<string> $warnings */
            public function __construct(private array &$warnings)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->warnings[] = (string) $message;
            }
        };

        return new DumpCompressor($configured, $logger);
    }

    private function part(): string
    {
        $file = $this->dir . '/bkp_2026-11-28_10-00-00_shop_full.sql.part';
        file_put_contents($file, self::SQL);

        return $file;
    }

    private function streamed(string $file): string
    {
        $content = '';
        $error = $this->compressor('none')->stream($file, static function (string $chunk) use (&$content): void {
            $content .= $chunk;
        });
        $this->assertNull($error);

        return $content;
    }

    private function requireBinary(BackupCompressionEnum $format): void
    {
        if (null === new ExecutableFinder()->find((string) $format->binary())) {
            self::markTestSkipped($format->binary() . ' is not installed.');
        }
    }
}
