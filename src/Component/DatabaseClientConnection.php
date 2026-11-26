<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\SqlClient;

/**
 * PDO connection registry for managed MySQL clients.
 *
 * Keeps one PDO per SqlClient for the lifetime of the PHP process (HTTP request or
 * console command). Connections are keyed by the client identity *and* its connection
 * parameters ({@see self::keyFor()}), so:
 *  - two different servers used in the same process never share a connection
 *    (and never share the session state set by `USE db`);
 *  - a client whose credentials change at runtime gets a fresh connection.
 *
 * Repositories built on the same SqlClient share its PDO, including the current
 * database selected with USE: always select the database explicitly before running
 * schema-dependent statements.
 *
 * The database is not selected in the DSN because this class manages server-level
 * connections; database selection happens at runtime via USE or qualified names.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @version 3.0
 */
final class DatabaseClientConnection
{
    /**
     * @var array<string, \PDO>
     */
    private static array $connections = [];

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    /**
     * Returns the PDO associated with the given SqlClient.
     * The connection is created on first access and reused in
     * subsequent calls with the same client and connection parameters.
     *
     * @throws \InvalidArgumentException if host or username are missing
     * @throws \PDOException             in case of connection error
     */
    public static function getInstance(SqlClient $sqlClient): \PDO
    {
        return self::$connections[self::keyFor($sqlClient)] ??= self::createConnection($sqlClient);
    }

    /**
     * Removes connections from the registry, forcing reconnection on the next call
     * to getInstance(): only the given client's connection, or all of them.
     * Useful in case of a recoverable error or in long-running processes.
     */
    public static function reset(?SqlClient $sqlClient = null): void
    {
        if (!$sqlClient instanceof SqlClient) {
            self::$connections = [];

            return;
        }

        unset(self::$connections[self::keyFor($sqlClient)]);
    }

    /**
     * Registry key: persisted id (or object identity for unsaved clients) plus every
     * connection parameter; the password is hashed so it is not kept as an array key.
     */
    private static function keyFor(SqlClient $sqlClient): string
    {
        return implode('|', [
            $sqlClient->getId() ?? 'new#' . spl_object_id($sqlClient),
            $sqlClient->getHost() ?? '',
            $sqlClient->getPort(),
            $sqlClient->getUsername() ?? '',
            hash('sha256', $sqlClient->getPassword() ?? ''),
        ]);
    }

    private static function createConnection(SqlClient $sqlClient): \PDO
    {
        $host = $sqlClient->getHost()
            ?? throw new \InvalidArgumentException('SqlClient: host is required.');

        $user = $sqlClient->getUsername()
            ?? throw new \InvalidArgumentException('SqlClient: username is required.');

        $pass = $sqlClient->getPassword() ?? '';
        $port = $sqlClient->getPort();

        $dsn = \sprintf(
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
