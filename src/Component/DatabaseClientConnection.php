<?php

namespace App\Component;

use App\Entity\SqlClient;

/**
 * Registry di connessioni PDO per client MySQL gestiti.
 *
 * Implementa il pattern Singleton-per-client: ogni SqlClient (identificato
 * dal proprio ID) mantiene una PDO dedicata per tutta la durata della
 * richiesta HTTP. Il database non viene selezionato nel DSN perché questa
 * classe gestisce connessioni a livello di server; la selezione del database
 * avviene a runtime tramite USE o query parametrizzate.
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
     * Restituisce la PDO associata al SqlClient dato.
     * La connessione viene creata al primo accesso e riutilizzata nelle
     * chiamate successive con lo stesso client.
     *
     * @throws \InvalidArgumentException se host o username sono mancanti
     * @throws \PDOException             in caso di errore di connessione
     */
    public static function getInstance(SqlClient $sqlClient): \PDO
    {
        if (null === self::$instance) {
            self::$instance = self::createConnection($sqlClient);
        }

        return self::$instance;
    }

    /**
     * Rimuove la connessione dal registry, forzando la riconnessione alla
     * prossima chiamata a getInstance(). Utile in caso di errore recuperabile.
     */
    public static function reset(): void
    {
        self::$instances = null;
    }

    private static function createConnection(SqlClient $sqlClient): \PDO
    {
        $host = $sqlClient->getHost()
            ?? throw new \InvalidArgumentException('SqlClient: host obbligatorio.');

        $user = $sqlClient->getUsername()
            ?? throw new \InvalidArgumentException('SqlClient: username obbligatorio.');

        $pass = $sqlClient->getPassword() ?? '';
        $port = $sqlClient->getPort() ?? 3306;

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
