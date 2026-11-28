<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\BackupCompressionEnum;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

/**
 * Description of BackupDump.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com>
 *
 * @see \App\Tests\Dto\BackupDumpTest
 */
final readonly class BackupDump
{
    /**
     * @param string      $filename    basename of the dump (also used as download name)
     * @param string      $path        absolute path on disk
     * @param string      $id          path relative to BACKUP_PATH: unique identifier used by the UI
     * @param int|null    $sqlClientId server the dump belongs to (null for legacy flat dumps)
     * @param string|null $dbName      database the dump belongs to (null for legacy flat dumps)
     */
    public function __construct(
        public string $filename,
        public string $path,
        public int $size,
        public int $mtime,
        public string $id = '',
        public ?int $sqlClientId = null,
        public ?string $dbName = null,
    ) {
    }

    /** Format of the dump, from its extension (.sql, .sql.gz, .sql.zst). */
    public function compression(): BackupCompressionEnum
    {
        return BackupCompressionEnum::fromFilename($this->filename);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $epti = new EffectivePrimitiveTypeIdentifierService();

        return new self(
            filename: $epti->getStringValueFromArray('filename', $row, forceString: true),
            path: $epti->getStringValueFromArray('path', $row, forceString: true),
            size: $epti->getIntValueFromArray('size', $row),
            mtime: $epti->getIntValueFromArray('mtime', $row),
            id: $epti->getStringValueFromArray('id', $row, forceString: true)
                ?: $epti->getStringValueFromArray('filename', $row, forceString: true),
            sqlClientId: isset($row['sql_client_id']) ? $epti->getIntValueFromArray('sql_client_id', $row) : null,
            dbName: isset($row['db_name']) ? $epti->getStringValueFromArray('db_name', $row, forceString: true) : null,
        );
    }
}
