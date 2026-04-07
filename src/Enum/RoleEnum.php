<?php

namespace App\Enum;

/**
 * @author Stefano Perrini <perrini.stefano@gmail.com>
 */
enum RoleEnum: string
{
    case ROLE_DISABLED = 'ROLE_DISABLED';
    case ROLE_USER = 'ROLE_USER';
    case ROLE_ADMIN = 'ROLE_ADMIN';

    public function label(): string
    {
        return match ($this) {
            self::ROLE_DISABLED => 'Disabled',
            self::ROLE_USER => 'User',
            self::ROLE_ADMIN => 'Administrator',
        };
    }
}
