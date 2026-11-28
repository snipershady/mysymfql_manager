<?php

declare(strict_types=1);

namespace App\Tests\Service\Audit;

use App\Service\Audit\SqlSecretRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlSecretRedactor::class)]
final class SqlSecretRedactorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function statements(): iterable
    {
        yield 'create user' => [
            "CREATE USER 'app'@'%' IDENTIFIED BY 'S3cret!'",
            "CREATE USER 'app'@'%' IDENTIFIED BY '***'",
        ];
        yield 'plugin and lowercase' => [
            "create user app identified with caching_sha2_password by 'x y z'",
            "create user app identified with caching_sha2_password by '***'",
        ];
        yield 'hash with AS' => [
            "ALTER USER app IDENTIFIED WITH mysql_native_password AS '*2470C0C06DEE42FD1618BB99005ADCA2EC9D1E19'",
            "ALTER USER app IDENTIFIED WITH mysql_native_password AS '***'",
        ];
        yield 'alter user with current password' => [
            "ALTER USER USER() IDENTIFIED BY 'new' REPLACE 'old'",
            "ALTER USER USER() IDENTIFIED BY '***' REPLACE '***'",
        ];
        yield 'set password for' => [
            "SET PASSWORD FOR 'app'@'localhost' = 'pw'",
            "SET PASSWORD FOR 'app'@'localhost' = '***'",
        ];
        yield 'set password' => [
            'SET PASSWORD = "pw"',
            "SET PASSWORD = '***'",
        ];
        yield 'escaped quotes' => [
            "CREATE USER a IDENTIFIED BY 'it''s \\' secret'; SELECT 1",
            "CREATE USER a IDENTIFIED BY '***'; SELECT 1",
        ];
        yield 'replication source' => [
            "CHANGE REPLICATION SOURCE TO SOURCE_USER='repl', SOURCE_PASSWORD='pw'",
            "CHANGE REPLICATION SOURCE TO SOURCE_USER='repl', SOURCE_PASSWORD='***'",
        ];
        yield 'server options' => [
            "CREATE SERVER s FOREIGN DATA WRAPPER mysql OPTIONS (USER 'u', PASSWORD 'pw')",
            "CREATE SERVER s FOREIGN DATA WRAPPER mysql OPTIONS (USER 'u', PASSWORD '***')",
        ];
        yield 'no secret' => [
            "SELECT REPLACE('abc', 'a', 'b'), name FROM users WHERE name = 'x'",
            "SELECT REPLACE('abc', 'a', 'b'), name FROM users WHERE name = 'x'",
        ];
        yield 'random password' => [
            'CREATE USER a IDENTIFIED BY RANDOM PASSWORD',
            'CREATE USER a IDENTIFIED BY RANDOM PASSWORD',
        ];
    }

    #[DataProvider('statements')]
    public function testHidesPasswordLiterals(string $sql, string $expected): void
    {
        $this->assertSame($expected, new SqlSecretRedactor()->redact($sql));
    }
}
