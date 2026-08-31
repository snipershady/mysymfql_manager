<?php

namespace App\RepositoryPDO;

use App\Component\DatabaseClientConnection;
use App\Entity\SqlClient;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

/**
 * Description of AbstractManagerRepositoryPDO.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
abstract readonly class AbstractManagerRepositoryPDO
{
    protected readonly \PDO $pdo;
    protected readonly EffectivePrimitiveTypeIdentifierService $epti;

    public function __construct(SqlClient $sqlClient)
    {
        $this->pdo = DatabaseClientConnection::getInstance($sqlClient);
        // The repository is instantiated with `new` (it depends on a runtime SqlClient),
        // so it cannot be autowired; the helper is stateless.
        $this->epti = new EffectivePrimitiveTypeIdentifierService();
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
