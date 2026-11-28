<?php

declare(strict_types=1);

namespace App\Attribute;

use App\Enum\AuditActionEnum;

/**
 * Records every non-safe request (POST, …) handled by the controller action in the audit log, with its outcome.
 * GET requests (form pages) are never recorded. See \App\EventListener\AuditListener.
 *
 * Requests refused before the action runs (firewall, access_control, #[IsGranted], #[IsCsrfTokenValid],
 * CSRF of /schema, payload validation) are not recorded: nothing was attempted.
 */
#[\Attribute(flags: \Attribute::TARGET_METHOD)]
final readonly class Audited
{
    public function __construct(
        public AuditActionEnum $action,
    ) {
    }
}
