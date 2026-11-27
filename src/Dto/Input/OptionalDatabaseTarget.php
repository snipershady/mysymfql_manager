<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Validator\Constraint\MysqlIdentifier;
use App\Validator\Constraint\ServerName;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * A server, optionally narrowed to one of its databases ("db_name" empty or absent = whole server).
 */
final readonly class OptionalDatabaseTarget
{
    #[SerializedName(serializedName: 'db_name'), MysqlIdentifier]
    public ?string $dbName;

    public function __construct(#[ServerName]
        public string $name, ?string $dbName = null)
    {
        $this->dbName = '' === $dbName ? null : $dbName;
    }
}
