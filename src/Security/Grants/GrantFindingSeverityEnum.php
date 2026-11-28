<?php

declare(strict_types=1);

namespace App\Security\Grants;

/**
 * How serious a finding of the server grant check is. "danger": a non-admin user can reach databases that are
 * not assigned to them (from the query console the account's grants are the only barrier).
 */
enum GrantFindingSeverityEnum: string
{
    case OK = 'ok';
    case INFO = 'info';
    case WARNING = 'warning';
    case DANGER = 'danger';

    public function label(): string
    {
        return match ($this) {
            self::OK => 'OK',
            self::INFO => 'Info',
            self::WARNING => 'Attenzione',
            self::DANGER => 'Rischio',
        };
    }

    /** Bootstrap contextual colour of the badge. */
    public function color(): string
    {
        return match ($this) {
            self::OK => 'success',
            self::INFO => 'info',
            self::WARNING => 'warning',
            self::DANGER => 'danger',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::OK => 0,
            self::INFO => 1,
            self::WARNING => 2,
            self::DANGER => 3,
        };
    }
}
