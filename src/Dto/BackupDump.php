<?php

namespace App\Dto;

/**
 * Description of BackupDump.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com>
 */
final readonly class BackupDump
{
    public function __construct(
        public string $filename,
        public string $path,
        public int $size,
        public int $mtime,
    ) {
    }

    /**
     * @param array{filename: string, path: string, size: int, mtime: int} $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            filename: (string) $row['filename'],
            path: (string) $row['path'],
            size: (int) $row['size'],
            mtime: (int) $row['mtime'],
        );
    }
}
