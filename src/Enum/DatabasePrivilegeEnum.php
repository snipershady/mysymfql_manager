<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Whitelist of the database-level privileges that the panel may GRANT.
 *
 * GRANT statements cannot use PDO placeholders for privilege keywords, so any
 * caller-supplied privilege list must be normalized through
 * {@see self::toGrantClause()} before being interpolated into SQL.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
enum DatabasePrivilegeEnum: string
{
    case ALL_PRIVILEGES = 'ALL PRIVILEGES';
    case SELECT = 'SELECT';
    case INSERT = 'INSERT';
    case UPDATE = 'UPDATE';
    case DELETE = 'DELETE';
    case CREATE = 'CREATE';
    case DROP = 'DROP';
    case INDEX = 'INDEX';
    case ALTER = 'ALTER';
    case REFERENCES = 'REFERENCES';
    case CREATE_VIEW = 'CREATE VIEW';
    case SHOW_VIEW = 'SHOW VIEW';
    case CREATE_ROUTINE = 'CREATE ROUTINE';
    case ALTER_ROUTINE = 'ALTER ROUTINE';
    case EXECUTE = 'EXECUTE';
    case TRIGGER = 'TRIGGER';
    case EVENT = 'EVENT';

    /**
     * Parses a comma-separated privilege list (e.g. "SELECT, insert,SHOW VIEW")
     * into a canonical, de-duplicated GRANT clause made only of whitelisted keywords.
     * If ALL PRIVILEGES is present, it supersedes any other entry.
     *
     * @throws \InvalidArgumentException when the list is empty or contains an unknown privilege
     */
    public static function toGrantClause(string $privileges): string
    {
        $parsed = [];
        foreach (explode(',', $privileges) as $token) {
            $normalized = strtoupper((string) preg_replace('/\s+/', ' ', trim($token)));
            if ('' === $normalized) {
                continue;
            }

            $privilege = self::tryFrom($normalized);
            if (!$privilege instanceof self) {
                throw new \InvalidArgumentException(\sprintf('Unsupported privilege "%s".', trim($token)));
            }

            if (self::ALL_PRIVILEGES === $privilege) {
                return self::ALL_PRIVILEGES->value;
            }

            $parsed[$privilege->value] = $privilege->value;
        }

        if ([] === $parsed) {
            throw new \InvalidArgumentException('Privilege list cannot be empty.');
        }

        return implode(', ', $parsed);
    }
}
