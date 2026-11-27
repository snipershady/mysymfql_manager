<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * Host part of a MySQL account ("%", "localhost", "192.168.1.%"…): at most 255 characters, no spaces.
 */
#[\Attribute(flags: \Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class MysqlHost extends Compound
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
            new Assert\NotBlank(message: "L'host dell'utente è obbligatorio.", allowNull: true),
            new Assert\Length(max: 255),
            new Assert\Regex(pattern: '/^[^\s\x00-\x1f]+$/u', message: "L'host non può contenere spazi o caratteri di controllo."),
        ];
    }
}
