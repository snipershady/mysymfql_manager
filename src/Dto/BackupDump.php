<?php

namespace App\Dto;

use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

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
        );
    }
}
