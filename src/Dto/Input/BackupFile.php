<?php

declare(strict_types=1);

namespace App\Dto\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A backup, by its id relative to BACKUP_PATH ("filename"); it is only ever matched against the
 * list of backups visible to the user, never used as a path directly.
 */
final readonly class BackupFile
{
    public function __construct(
        #[Assert\NotBlank, Assert\Length(max: 255)]
        public string $filename,
    ) {
    }
}
