<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Validator\Constraint\MysqlAccount;
use App\Validator\Constraint\MysqlHost;
use App\Validator\Constraint\ServerName;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * New password for a MySQL account. Passwords are never trimmed.
 */
final readonly class AccountPassword
{
    public function __construct(
        #[ServerName]
        public string $name,
        #[MysqlAccount]
        public string $username,
        #[SerializedName(serializedName: 'user_host'), MysqlHost]
        public string $userHost,
        #[\SensitiveParameter]
        #[Assert\NotBlank(message: 'Inserisci la nuova password.')]
        public string $password,
    ) {
    }
}
