<?php

declare(strict_types=1);

namespace App\Security\Grants;

/**
 * Result of the grant check of one registered server (SqlClient).
 */
final readonly class ServerGrantReport
{
    /**
     * @param list<array{severity: GrantFindingSeverityEnum, message: string}> $findings
     * @param list<string>                                                     $grants        SHOW GRANTS output
     * @param list<string>                                                     $nonAdminUsers panel users without ROLE_ADMIN who reach the server
     * @param list<string>                                                     $assignedDbs   databases assigned (DatabaseOwner) on the server
     */
    public function __construct(
        public string $serverName,
        public ?string $account,
        public array $findings,
        public array $grants = [],
        public array $nonAdminUsers = [],
        public array $assignedDbs = [],
        public ?string $error = null,
    ) {
    }

    /** Worst severity among the findings (DANGER when the server could not be checked). */
    public function severity(): GrantFindingSeverityEnum
    {
        if (null !== $this->error) {
            return GrantFindingSeverityEnum::DANGER;
        }

        $worst = GrantFindingSeverityEnum::OK;
        foreach ($this->findings as $finding) {
            if ($finding['severity']->weight() > $worst->weight()) {
                $worst = $finding['severity'];
            }
        }

        return $worst;
    }
}
