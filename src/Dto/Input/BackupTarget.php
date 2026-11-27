<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Validator\Constraint\MysqlIdentifier;
use App\Validator\Constraint\ServerName;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Backup / restore target: a whole database, or one table when "table" is given (empty = whole database).
 */
final readonly class BackupTarget
{
    #[MysqlIdentifier]
    public ?string $table;

    public function __construct(#[ServerName]
        public string $name, #[SerializedName(serializedName: 'db_name'), MysqlIdentifier]
        public string $dbName, ?string $table = null)
    {
        $this->table = '' === $table ? null : $table;
    }
}
