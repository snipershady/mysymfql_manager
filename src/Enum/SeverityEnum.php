<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How serious a finding is (server grant check, cockpit health checks). For the grant check, "danger" means
 * a non-admin user can reach databases not assigned to them (from the query console the account grants are the only barrier).
 */
enum SeverityEnum: string
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
