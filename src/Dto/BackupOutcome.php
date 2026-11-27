<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Result of a backup or restore run by {@see \App\Service\BackupService}.
 *
 * $error is the native output of mysqldump/mysql (it names hosts and accounts): the panel is a tool for
 * system administrators, who need the real message to act on it.
 */
final readonly class BackupOutcome
{
    /**
     * @param string $filename name of the dump file (no directory)
     */
    private function __construct(
        public bool $ok,
        public string $filename,
        public string $error,
    ) {
    }

    public static function success(string $filename): self
    {
        return new self(ok: true, filename: $filename, error: '');
    }

    public static function failure(string $filename, string $error): self
    {
        return new self(ok: false, filename: $filename, error: '' === trim($error) ? 'Errore sconosciuto.' : trim($error));
    }
}
