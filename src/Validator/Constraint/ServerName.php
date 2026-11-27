<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * Name of a managed server (SqlClient::$name, 31 characters).
 */
#[\Attribute(flags: \Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class ServerName extends Compound
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
            new Assert\NotBlank(message: 'Seleziona un server.', allowNull: true),
            new Assert\Length(max: 31),
            new Assert\Regex(pattern: '/^\S(.*\S)?$/su', message: 'Non sono ammessi spazi iniziali o finali.'),
        ];
    }
}
