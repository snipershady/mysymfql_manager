<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Validator\Constraint\MysqlIdentifier;
use App\Validator\Constraint\ServerName;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Restore of a visible backup ("backup_filename", its id relative to BACKUP_PATH) into "db_name".
 */
final readonly class RestoreBackup
{
    public function __construct(
        #[ServerName]
        public string $name,
        #[SerializedName(serializedName: 'db_name'), MysqlIdentifier]
        public string $dbName,
        #[SerializedName(serializedName: 'backup_filename'), Assert\NotBlank, Assert\Length(max: 255)]
        public string $backupFilename,
    ) {
    }
}
