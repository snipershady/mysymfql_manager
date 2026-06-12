<?php

namespace App\Component;

use App\Entity\SqlClient;

/**
 * PDO connection registry for managed MySQL clients.
 *
 * Implements the Singleton-per-client pattern: each SqlClient (identified
 * by its own ID) maintains a dedicated PDO for the entire duration of the
 * HTTP request. The database is not selected in the DSN because this
 * class manages server-level connections; database selection happens
 * at runtime via USE or parameterised queries.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @version 2.0
 */
final class DatabaseClientConnection
{
    private static ?\PDO $instance = null;

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    /**
     * Returns the PDO associated with the given SqlClient.
     * The connection is created on first access and reused in
     * subsequent calls with the same client.
     *
     * @throws \InvalidArgumentException if host or username are missing
     * @throws \PDOException             in case of connection error
     */
    public static function getInstance(SqlClient $sqlClient): \PDO
    {
        if (null === self::$instance) {
            self::$instance = self::createConnection($sqlClient);
        }

        return self::$instance;
    }

    /**
     * Removes the connection from the registry, forcing reconnection on
     * the next call to getInstance(). Useful in case of a recoverable error.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    private static function createConnection(SqlClient $sqlClient): \PDO
    {
        $host = $sqlClient->getHost()
            ?? throw new \InvalidArgumentException('SqlClient: host is required.');

        $user = $sqlClient->getUsername()
            ?? throw new \InvalidArgumentException('SqlClient: username is required.');

        $pass = $sqlClient->getPassword() ?? '';
        $port = $sqlClient->getPort();

        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=utf8mb4',
            $host,
            $port
        );

        return new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_PERSISTENT => false,
        ]);
    }
}
