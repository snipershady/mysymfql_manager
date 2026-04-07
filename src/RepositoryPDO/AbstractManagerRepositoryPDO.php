<?php

namespace App\RepositoryPDO;

use App\Component\DatabaseClientConnection;
use App\Entity\SqlClient;

/**
 * Description of AbstractManagerRepositoryPDO.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
abstract readonly class AbstractManagerRepositoryPDO
{
    protected readonly \PDO $pdo;

    public function __construct(SqlClient $sqlClient)
    {
        $this->pdo = DatabaseClientConnection::getInstance($sqlClient);
    }
}
