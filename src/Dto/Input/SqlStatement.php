<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Validator\Constraint\MysqlIdentifier;
use App\Validator\Constraint\ServerName;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * SQL typed in the query console ("sql"), run on "db_name" of server "name".
 */
final readonly class SqlStatement
{
    public function __construct(
        #[ServerName]
        public string $name,
        #[SerializedName(serializedName: 'db_name'), MysqlIdentifier]
        public string $dbName,
        #[Assert\NotBlank(message: 'La query non può essere vuota.', normalizer: 'trim')]
        public string $sql,
    ) {
    }
}
