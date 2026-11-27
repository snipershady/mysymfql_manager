<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * Database or table name as MySQL accepts it: at most 64 characters, none of "/", "\" or "." and no
 * control characters (https://dev.mysql.com/doc/refman/8.4/en/identifiers.html). Identifiers are always
 * quoted with backticks in SQL, so any other character is safe. Invalid names are rejected, never altered.
 */
#[\Attribute(flags: \Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class MysqlIdentifier extends Compound
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
            new Assert\NotBlank(allowNull: true),
            new Assert\Length(max: 64),
            new Assert\Regex(pattern: '#^[^/\\\\.\x00-\x1f]+$#u', message: 'Il nome non può contenere "/", "\\", "." o caratteri di controllo.'),
            new Assert\Regex(pattern: '/^\S(.*\S)?$/su', message: 'Non sono ammessi spazi iniziali o finali.'),
        ];
    }
}
