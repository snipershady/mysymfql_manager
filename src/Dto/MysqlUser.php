<?php

namespace App\Dto;

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
     * @param array{User: string, Host: string, account_locked: string, has_db_grant: int|string} $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            user: (string) $row['User'],
            host: (string) $row['Host'],
            accountLocked: 'Y' === $row['account_locked'],
            hasDbGrant: (bool) $row['has_db_grant'],
        );
    }
}
