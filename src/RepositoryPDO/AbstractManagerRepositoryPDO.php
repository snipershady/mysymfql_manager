<?php

declare(strict_types=1);

namespace App\RepositoryPDO;

use App\Component\DatabaseClientConnection;
use App\Entity\SqlClient;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierServiceInterface;

/**
 * Base of the repositories working on a managed MySQL server through native PDO.
 *
 * They depend on a runtime SqlClient, so they are not services: the application gets them from
 * {@see ManagedServerRepositoryFactory}. Every repository of the same SqlClient shares one PDO connection
 * ({@see DatabaseClientConnection}), and with it the schema selected by USE.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
abstract readonly class AbstractManagerRepositoryPDO
{
    protected \PDO $pdo;
    protected EffectivePrimitiveTypeIdentifierServiceInterface $epti;

    public function __construct(SqlClient $sqlClient, ?EffectivePrimitiveTypeIdentifierServiceInterface $epti = null)
    {
        $this->pdo = DatabaseClientConnection::getInstance($sqlClient);
        $this->epti = $epti ?? new EffectivePrimitiveTypeIdentifierService();
    }

    /**
     * Escapes a MySQL identifier (database name, table name, etc.) by wrapping
     * it in backticks and doubling any backtick present in the value.
     */
    protected function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Fetches every row of an executed statement as an associative array,
     * normalising keys to string so callers get a stable `array<string, mixed>`.
     *
     * @return list<array<string, mixed>>
     */
    protected function fetchAllAssoc(\PDOStatement $stmt): array
    {
        $out = [];
        while (true) {
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!\is_array($row)) {
                break;
            }
            $assoc = [];
            foreach ($row as $key => $value) {
                $assoc[(string) $key] = $value;
            }
            $out[] = $assoc;
        }

        return $out;
    }

    /**
     * Fetches the first row of an executed statement as an associative array,
     * or an empty array when the statement yields no row.
     *
     * @return array<string, mixed>
     */
    protected function fetchOneAssoc(\PDOStatement $stmt): array
    {
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row)) {
            return [];
        }

        $assoc = [];
        foreach ($row as $key => $value) {
            $assoc[(string) $key] = $value;
        }

        return $assoc;
    }

    /**
     * Fetches a single-column result set as a list of strings.
     *
     * @return list<string>
     */
    protected function fetchColumnList(\PDOStatement $stmt): array
    {
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $value) {
            $out[] = \is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }
}
