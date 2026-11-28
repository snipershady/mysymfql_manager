<?php

declare(strict_types=1);

namespace App\Security\Grants;

/**
 * Reads the GRANT statements printed by SHOW GRANTS (MySQL 8):
 *
 *   GRANT USAGE ON *.* TO `u`@`%`
 *   GRANT SELECT, INSERT ON *.* TO `u`@`%`                       global privileges
 *   GRANT BACKUP_ADMIN,SYSTEM_VARIABLES_ADMIN ON *.* TO `u`@`%`  dynamic (global) privileges
 *   GRANT ALL PRIVILEGES ON `shop`.* TO `u`@`%` WITH GRANT OPTION database privileges
 *   GRANT SELECT (`a`), UPDATE ON `shop`.`orders` TO `u`@`%`     table / column privileges
 *   GRANT EXECUTE ON PROCEDURE `shop`.`p` TO `u`@`%`             routine privileges
 *   GRANT `reporting`@`%` TO `u`@`%`                             roles
 *   GRANT PROXY ON ``@`` TO `u`@`%`                              proxy
 *   REVOKE … (partial revokes: they only restrict, ignored)
 *
 * Pure: no connection. Lines that match none of these forms end up in ParsedGrants::$unparsed.
 *
 * @see \App\Tests\Security\Grants\GrantStatementParserTest
 */
final class GrantStatementParser
{
    /** A backtick-quoted identifier, with doubled backticks. */
    private const string IDENTIFIER = '`(?:[^`]|``)*`';

    /**
     * @param list<string> $statements
     */
    public function parse(array $statements): ParsedGrants
    {
        $global = [];
        $databases = [];
        $roles = [];
        $grantOption = false;
        $proxy = false;
        $unparsed = [];

        $privilegeGrant = \sprintf(
            '/^GRANT (?<privileges>.+?) ON (?:(?:PROCEDURE|FUNCTION) )?(?<level>\*\.\*|(?<db>%1$s)\.(?:\*|%1$s)) TO .+?(?<option> WITH GRANT OPTION)?$/s',
            self::IDENTIFIER,
        );
        $proxyGrant = '/^GRANT PROXY ON .+ TO .+$/s';
        $roleGrant = \sprintf('/^GRANT (?<roles>%1$s(?:@%1$s)?(?:\s*,\s*%1$s(?:@%1$s)?)*) TO .+$/s', self::IDENTIFIER);

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ('' === $statement || str_starts_with($statement, 'REVOKE ')) {
                continue;
            }

            if (1 === preg_match($proxyGrant, $statement)) {
                $proxy = true;
                $grantOption = $grantOption || str_ends_with($statement, ' WITH GRANT OPTION');

                continue;
            }

            if (1 === preg_match($privilegeGrant, $statement, $matches)) {
                $privileges = $this->privileges($matches['privileges']);
                $grantOption = $grantOption || '' !== ($matches['option'] ?? '');
                if ('*.*' === $matches['level']) {
                    $global = [...$global, ...array_filter($privileges, static fn (string $privilege): bool => 'USAGE' !== $privilege)];
                } else {
                    $db = $this->unquote($matches['db'] ?? '``');
                    $databases[$db] = array_values(array_unique([...$databases[$db] ?? [], ...$privileges]));
                }

                continue;
            }

            if (1 === preg_match($roleGrant, $statement, $matches)) {
                $roles = [...$roles, ...array_map(trim(...), explode(',', $matches['roles']))];

                continue;
            }

            $unparsed[] = $statement;
        }

        return new ParsedGrants(
            globalPrivileges: array_values(array_unique($global)),
            databases: $databases,
            roles: $roles,
            grantOption: $grantOption,
            proxy: $proxy,
            unparsed: $unparsed,
        );
    }

    /**
     * "SELECT (`a`, `b`), INSERT" → ["SELECT", "INSERT"]: commas inside the column lists are not separators.
     *
     * @return list<string>
     */
    private function privileges(string $list): array
    {
        $withoutColumns = preg_replace('/\s*\([^)]*\)/', '', $list) ?? $list;

        return array_values(array_filter(array_map(trim(...), explode(',', $withoutColumns)), static fn (string $privilege): bool => '' !== $privilege));
    }

    private function unquote(string $identifier): string
    {
        return str_replace('``', '`', substr($identifier, 1, -1));
    }
}
