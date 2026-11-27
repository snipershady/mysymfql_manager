<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Validator\Constraint\ServerName;

/**
 * A managed server, by name (query string or form field "name").
 */
final readonly class ServerTarget
{
    public function __construct(
        #[ServerName]
        public string $name,
    ) {
    }
}
