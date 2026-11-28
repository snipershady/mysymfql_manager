<?php

declare(strict_types=1);

namespace App\Service\Audit;

/**
 * Hides the passwords written in an SQL statement before it is stored in the audit log: the string literal
 * after IDENTIFIED BY / IDENTIFIED WITH plugin BY|AS, after REPLACE (current password of ALTER USER), and after
 * any keyword containing PASSWORD (SET PASSWORD [FOR …] = '…', PASSWORD('…'), SOURCE_PASSWORD = '…',
 * OPTIONS (PASSWORD '…')) becomes '***'.
 *
 * Best effort on the syntax MySQL documents for credentials: it is not a parser, and a secret passed in any
 * other form (a plain string in a table, a variable) is stored as typed.
 *
 * @see \App\Tests\Service\Audit\SqlSecretRedactorTest
 */
final class SqlSecretRedactor
{
    public const string MASK = "'***'";

    /** A single- or double-quoted literal, with backslash escapes and doubled quotes. */
    private const string LITERAL = <<<'REGEX'
        (?:'(?:[^'\\]|\\.|'')*'|"(?:[^"\\]|\\.|"")*")
        REGEX;

    private const string KEYWORD = <<<'REGEX'
        (?:\bIDENTIFIED(?:\s+WITH\s+\S+)?\s+(?:BY|AS)|\bREPLACE|\w*PASSWORD\w*\s+FOR\s+\S+\s*=|\w*PASSWORD\w*\s*(?:=|\()?)
        REGEX;

    public function redact(string $sql): string
    {
        $pattern = '/(' . self::KEYWORD . '\s*)' . self::LITERAL . '/is';

        return preg_replace($pattern, '$1' . self::MASK, $sql) ?? $sql;
    }
}
