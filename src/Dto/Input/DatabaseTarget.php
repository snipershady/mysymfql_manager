<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Validator\Constraint\MysqlIdentifier;
use App\Validator\Constraint\ServerName;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * A database of a managed server ("name", "db_name").
 */
final readonly class DatabaseTarget
{
    public function __construct(
        #[ServerName]
        public string $name,
        #[SerializedName(serializedName: 'db_name'), MysqlIdentifier]
        public string $dbName,
    ) {
    }
}
