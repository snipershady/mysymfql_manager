<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Enum\CharsetEnum;
use App\Enum\CollationEnum;
use App\Enum\DatabasePrivilegeEnum;
use App\Validator\Constraint\MysqlAccount;
use App\Validator\Constraint\MysqlHost;
use App\Validator\Constraint\MysqlIdentifier;
use App\Validator\Constraint\ServerName;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Database to create together with its MySQL account. Charset and collation are enums: an unknown value
 * is a validation error (it used to fall back silently to the default).
 */
final readonly class NewDatabase
{
    public function __construct(
        #[ServerName]
        public string $name,
        #[SerializedName(serializedName: 'db_name'), MysqlIdentifier]
        public string $dbName,
        #[MysqlAccount]
        public string $username,
        #[\SensitiveParameter]
        #[Assert\NotBlank(message: 'La password è obbligatoria.')]
        public string $password,
        #[SerializedName(serializedName: 'user_host'), MysqlHost]
        public string $userHost,
        public string $privileges,
        public CharsetEnum $charset = CharsetEnum::UTF8MB4,
        public CollationEnum $collation = CollationEnum::UTF8MB4_0900_AI_CI,
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
