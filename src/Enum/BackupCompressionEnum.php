<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Compression of the dumps (BACKUP_COMPRESSION, default zstd). The format of an existing dump is read from its
 * extension: bkp_….sql (none), bkp_….sql.gz (gzip), bkp_….sql.zst (zstd), so changing the setting never
 * breaks the listing, the restore or the rotation of older dumps.
 *
 * Measured on a 419 MB dump: zstd -3 -T0 → 121 MB in 0.3 s, gzip -6 → 123 MB in 7.6 s.
 */
enum BackupCompressionEnum: string
{
    case NONE = 'none';
    case GZIP = 'gzip';
    case ZSTD = 'zstd';

    /** Extensions a dump may have, longest first. */
    private const array DUMP_EXTENSIONS = ['.sql.zst', '.sql.gz', '.sql'];

    /** Extension appended to ".sql" (empty for an uncompressed dump). */
    public function extension(): string
    {
        return match ($this) {
            self::NONE => '',
            self::GZIP => '.gz',
            self::ZSTD => '.zst',
        };
    }

    /** CLI tool that compresses and decompresses the format (null for an uncompressed dump). */
    public function binary(): ?string
    {
        return match ($this) {
            self::NONE => null,
            self::GZIP => 'gzip',
            self::ZSTD => 'zstd',
        };
    }

    /**
     * Arguments compressing $file in place: the tool writes "$file<extension()>" and removes $file on success.
     *
     * @return list<string>
     */
    public function compressArguments(string $file): array
    {
        return match ($this) {
            self::NONE => [],
            self::GZIP => ['-6', '--', $file],
            // -T0: one thread per core; --rm: delete the source once the compressed file is complete.
            self::ZSTD => ['-3', '-T0', '-q', '--rm', '--', $file],
        };
    }

    /**
     * Arguments writing the decompressed content of $file to standard output.
     *
     * @return list<string>
     */
    public function decompressArguments(string $file): array
    {
        return match ($this) {
            self::NONE => [],
            self::GZIP => ['-d', '-c', '--', $file],
            self::ZSTD => ['-d', '-c', '-q', '--', $file],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'non compresso',
            self::GZIP => 'gzip',
            self::ZSTD => 'zstd',
        };
    }

    /** Format of a dump, from its file name (anything not ending in .gz/.zst is an uncompressed dump). */
    public static function fromFilename(string $filename): self
    {
        return match (true) {
            str_ends_with($filename, '.sql.zst') => self::ZSTD,
            str_ends_with($filename, '.sql.gz') => self::GZIP,
            default => self::NONE,
        };
    }

    /** True for the names of complete dumps: bkp_….sql, bkp_….sql.gz, bkp_….sql.zst. */
    public static function isDumpFilename(string $filename): bool
    {
        return str_starts_with($filename, 'bkp_') && array_any(self::DUMP_EXTENSIONS, static fn (string $extension): bool => str_ends_with($filename, $extension));
    }

    /** File name without the dump extension: "bkp_…_shop_full.sql.zst" → "bkp_…_shop_full". */
    public static function baseName(string $filename): string
    {
        foreach (self::DUMP_EXTENSIONS as $extension) {
            if (str_ends_with($filename, $extension)) {
                return substr($filename, 0, -\strlen($extension));
            }
        }

        return $filename;
    }
}
