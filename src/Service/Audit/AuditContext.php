<?php

declare(strict_types=1);

namespace App\Service\Audit;

/**
 * What an audited request acts on: server, database, object (table, MySQL account, PID, dump, panel record)
 * and the remaining non-secret parameters.
 */
final readonly class AuditContext
{
    /**
     * @param array<array-key, mixed>|null $details
     */
    public function __construct(
        public ?string $serverName = null,
        public ?string $dbName = null,
        public ?string $target = null,
        public ?array $details = null,
    ) {
    }
}
