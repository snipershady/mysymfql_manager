<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Enum\DatabasePrivilegeEnum;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Validation of a comma-separated privilege list against DatabasePrivilegeEnum (shared by the input DTOs).
 */
final class PrivilegeClause
{
    public static function validate(string $privileges, ExecutionContextInterface $context, string $path = 'privileges'): void
    {
        try {
            DatabasePrivilegeEnum::toGrantClause($privileges);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            $context->buildViolation($invalidArgumentException->getMessage())->atPath($path)->addViolation();
        }
    }
}
