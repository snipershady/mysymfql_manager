<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Validator\Constraint\MysqlAccount;
use App\Validator\Constraint\MysqlHost;
use App\Validator\Constraint\MysqlIdentifier;
use App\Validator\Constraint\ServerName;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Grants to set and databases to revoke for a MySQL account (JSON request body).
 */
final readonly class GrantChanges
{
    /**
     * @param list<GrantInput> $grants
     * @param list<string>     $revokedDbs
     */
    public function __construct(
        #[ServerName]
        public string $name,
        #[MysqlAccount]
        public string $username,
        #[SerializedName(serializedName: 'user_host'), MysqlHost]
        public string $userHost,
        #[Assert\Valid]
        public array $grants = [],
        #[SerializedName(serializedName: 'revoked_dbs'), Assert\All(constraints: [new MysqlIdentifier()])]
        public array $revokedDbs = [],
    ) {
    }
}
