<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Validator\Constraint\MysqlIdentifier;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One database grant of a GrantChanges request.
 */
final readonly class GrantInput
{
    public function __construct(
        #[MysqlIdentifier]
        public string $db,
        public string $privileges,
    ) {
    }

    #[Assert\Callback]
    public function validatePrivileges(ExecutionContextInterface $context): void
    {
        PrivilegeClause::validate($this->privileges, $context);
    }
}
