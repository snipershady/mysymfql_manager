<?php

namespace App\Dto;

use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

/**
 * @author Stefano Perrini <perrini.stefano@gmail.com>
 */
final readonly class MysqlUser
{
    public function __construct(
        public string $user,
        public string $host,
        public bool $accountLocked,
        public bool $hasDbGrant,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $epti = new EffectivePrimitiveTypeIdentifierService();

        return new self(
            user: $epti->getStringValueFromArray('User', $row, forceString: true),
            host: $epti->getStringValueFromArray('Host', $row, forceString: true),
            accountLocked: 'Y' === $epti->getStringValueFromArray('account_locked', $row, forceString: true),
            hasDbGrant: $epti->getBoolValueFromArray('has_db_grant', $row),
        );
    }
}
