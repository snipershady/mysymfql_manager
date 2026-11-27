<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Validator\Constraint\MysqlIdentifier;
use App\Validator\Constraint\ServerName;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * A table of a database ("name", "db_name", "table").
 */
final readonly class TableTarget
{
    public function __construct(
        #[ServerName]
        public string $name,
        #[SerializedName(serializedName: 'db_name'), MysqlIdentifier]
        public string $dbName,
        #[MysqlIdentifier]
        public string $table,
    ) {
    }
}
