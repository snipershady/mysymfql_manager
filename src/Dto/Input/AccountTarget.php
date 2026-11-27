<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Validator\Constraint\MysqlAccount;
use App\Validator\Constraint\MysqlHost;
use App\Validator\Constraint\ServerName;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * A MySQL account ("username"@"user_host") of server "name".
 */
final readonly class AccountTarget
{
    public function __construct(
        #[ServerName]
        public string $name,
        #[MysqlAccount]
        public string $username,
        #[SerializedName(serializedName: 'user_host'), MysqlHost]
        public string $userHost,
    ) {
    }
}
