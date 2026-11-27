<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Enum\DatabasePrivilegeEnum;
use App\Validator\Constraint\MysqlAccount;
use App\Validator\Constraint\MysqlHost;
use App\Validator\Constraint\MysqlIdentifier;
use App\Validator\Constraint\ServerName;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * MySQL account to create with privileges on database "db_name".
 */
final readonly class NewAccount
{
    public function __construct(
        #[ServerName]
        public string $name,
        #[SerializedName(serializedName: 'db_name'), MysqlIdentifier]
        public string $dbName,
        #[MysqlAccount]
        public string $username,
        #[Assert\NotBlank(message: 'La password è obbligatoria.')]
        public string $password,
        #[SerializedName(serializedName: 'user_host'), MysqlHost]
        public string $userHost,
        public string $privileges,
    ) {
    }

    #[Assert\Callback]
    public function validatePrivileges(ExecutionContextInterface $context): void
    {
        PrivilegeClause::validate($this->privileges, $context);
    }

    /** Normalized, whitelisted GRANT clause (valid once the DTO passed validation). */
    public function grantClause(): string
    {
        return DatabasePrivilegeEnum::toGrantClause($this->privileges);
    }
}
