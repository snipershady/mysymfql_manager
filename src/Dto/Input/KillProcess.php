<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Validator\Constraint\ServerName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Thread to kill ("pid", a processlist ID) on server "name".
 */
final readonly class KillProcess
{
    public function __construct(
        #[ServerName]
        public string $name,
        #[Assert\Positive(message: 'Il PID deve essere un numero positivo.')]
        public int $pid,
    ) {
    }
}
