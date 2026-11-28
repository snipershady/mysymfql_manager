<?php

declare(strict_types=1);

namespace App\Security\Grants;

/**
 * What an account may do, read from its SHOW GRANTS output (see GrantStatementParser).
 */
final readonly class ParsedGrants
{
    /**
     * @param list<string>                $globalPrivileges privileges ON *.* (static and dynamic), USAGE excluded
     * @param array<string, list<string>> $databases        database name or pattern (as granted, without backticks)
     *                                                      → privileges, table and routine grants included
     * @param list<string>                $roles            roles granted to the account
     * @param list<string>                $unparsed         lines not understood (reported, never ignored silently)
     */
    public function __construct(
        public array $globalPrivileges = [],
        public array $databases = [],
        public array $roles = [],
        public bool $grantOption = false,
        public bool $proxy = false,
        public array $unparsed = [],
    ) {
    }

    /**
     * True when a database pattern is a LIKE wildcard: an unescaped "%" matches any sequence of characters.
     * (An unescaped "_" matches one character: names like "shop_2026" are granted that way by default and are
     * compared literally, see literalName().).
     */
    public static function isWildcard(string $pattern): bool
    {
        return 1 === preg_match('/(?<!\\\\)%/', $pattern);
    }

    /** Name of a non-wildcard pattern: "app\_db" → "app_db". */
    public static function literalName(string $pattern): string
    {
        return preg_replace('/\\\\([_%\\\\])/', '$1', $pattern) ?? $pattern;
    }
}
