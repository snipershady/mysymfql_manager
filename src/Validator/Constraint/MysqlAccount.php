<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * MySQL account user name (at most 32 characters). Account strings are always passed through
 * PDO::quote(), so only control characters and surrounding spaces are refused.
 */
#[\Attribute(flags: \Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class MysqlAccount extends Compound
{
    /**
     * @param array<mixed> $options
     *
     * @return list<Constraint>
     */
    #[\Override]
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(message: 'Il nome utente è obbligatorio.', allowNull: true),
            new Assert\Length(max: 32),
            new Assert\Regex(pattern: '/^[^\x00-\x1f]+$/u', message: 'Il nome utente non può contenere caratteri di controllo.'),
            new Assert\Regex(pattern: '/^\S(.*\S)?$/su', message: 'Non sono ammessi spazi iniziali o finali.'),
        ];
    }
}
