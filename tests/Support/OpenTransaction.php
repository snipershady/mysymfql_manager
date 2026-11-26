<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SqlClient;

/**
 * Opens an InnoDB transaction on a dedicated connection (not the panel's registry), optionally
 * with a current schema, so it shows up in INNODB_TRX and in the process list. Close it with close().
 *
 * MySQL serves INNODB_TRX from a cache refreshed at most every 0.1 s: use {@see self::eventually()}
 * to assert on it right after opening or closing a transaction.
 */
final class OpenTransaction
{
    private ?\PDO $pdo;

    public readonly int $threadId;

    public function __construct(SqlClient $sqlClient, ?string $dbName = null)
    {
        $this->pdo = new \PDO(
            \sprintf('mysql:host=%s;port=%d', $sqlClient->getHost(), $sqlClient->getPort()),
            $sqlClient->getUsername(),
            $sqlClient->getPassword(),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        if (null !== $dbName) {
            $this->pdo->exec(\sprintf('USE `%s`', str_replace('`', '``', $dbName)));
        }
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $this->threadId = (int) $this->pdo->query('SELECT CONNECTION_ID()')?->fetchColumn();
    }

    public function close(): void
    {
        if ($this->pdo instanceof \PDO) {
            $this->pdo->exec('ROLLBACK');
            $this->pdo = null;
        }
    }

    /**
     * Polls $probe until it returns true (max ~2 s); returns the last result.
     *
     * @param callable(): bool $probe
     */
    public static function eventually(callable $probe): bool
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            if ($probe()) {
                return true;
            }
            usleep(100_000);
        }

        return false;
    }
}
