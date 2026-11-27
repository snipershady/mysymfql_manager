<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Validator\Constraint\ServerName;

/**
 * Optional server selection ("name"): an empty value means "no server selected".
 */
final readonly class OptionalServer
{
    #[ServerName]
    public ?string $name;

    public function __construct(?string $name = null)
    {
        $this->name = '' === $name ? null : $name;
    }
}
