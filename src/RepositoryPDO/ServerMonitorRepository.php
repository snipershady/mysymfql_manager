<?php

declare(strict_types=1);

namespace App\RepositoryPDO;

use App\Dto\InnodbStatus;
use App\Dto\InnodbTransaction;
use App\Dto\ProcessList;
use App\Exception\RepositoryException;

/**
 * Monitoring of a managed server: threads, InnoDB status and transactions, connection counters, KILL.
 *
 * Instances come from {@see ManagedServerRepositoryFactory}; MySQL privileges: PROCESS (and
 * CONNECTION_ADMIN or SUPER to kill threads of other accounts).
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
readonly class ServerMonitorRepository extends AbstractManagerRepositoryPDO
{
    public function killProcessById(int $processId): bool
    {
        $query = \sprintf('KILL %s', $processId);
        try {
            $stmt = $this->pdo->prepare($query);

            return $stmt->execute();
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function showEngineInnodbStatus(): InnodbStatus
    {
        $query = 'SHOW ENGINE INNODB STATUS';
        try {
            $stmt = $this->pdo->prepare($query);

            $stmt->execute();

            return InnodbStatus::fromArray($this->fetchOneAssoc($stmt));
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * <p>
     * -- Check if the db is enabled to show processes from performance_schema
     * SHOW VARIABLES LIKE 'performance_schema_show_processlist';
     * -- If it is OFF, enable it (requires global privileges)
     * SET GLOBAL performance_schema_show_processlist = ON;
     * </p>.
     *
     * @return list<ProcessList>
     *
     * @throws RepositoryException
     */
    public function showProcessList(): array
    {
        $query = 'SELECT * FROM performance_schema.processlist;';
        try {
            $stmt = $this->pdo->prepare($query);

            $stmt->execute();

            $result = [];
            foreach ($this->fetchAllAssoc($stmt) as $row) {
                $result[] = ProcessList::fromArray($row);
            }

            return $result;
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    /**
     * Open InnoDB transactions on the server. Requires the PROCESS privilege
     * (without it MySQL returns only the transactions of the current account).
     *
     * @return list<InnodbTransaction>
     *
     * @throws RepositoryException
     */
    public function showInnodbTransactions(): array
    {
        $query = 'SELECT trx_mysql_thread_id, trx_state, trx_started, trx_rows_locked FROM information_schema.INNODB_TRX;';
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();

            return array_map(InnodbTransaction::fromArray(...), $this->fetchAllAssoc($stmt));
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function getActiveConnections(): int
    {
        $query = "SHOW STATUS LIKE 'Threads_connected'";
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();

            return $this->epti->getIntValueFromArray('Value', $this->fetchOneAssoc($stmt));
        } catch (\PDOException $pdoException) {
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function getRunningProcesses(): ?int
    {
        $query = "SELECT COUNT(*) as cnt FROM performance_schema.processlist WHERE COMMAND != 'Sleep'";
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();

            return $this->epti->getIntValueFromArray('cnt', $this->fetchOneAssoc($stmt));
        } catch (\PDOException $pdoException) {
            // MySQL error 1227: insufficient PROCESS privilege — degrade gracefully
            if (isset($pdoException->errorInfo[1]) && 1227 === $pdoException->errorInfo[1]) {
                return null;
            }
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }

    public function getBlockedProcesses(): ?int
    {
        $query = "SELECT COUNT(*) as cnt FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'";
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute();

            return $this->epti->getIntValueFromArray('cnt', $this->fetchOneAssoc($stmt));
        } catch (\PDOException $pdoException) {
            // MySQL error 1227: insufficient PROCESS privilege — degrade gracefully
            if (isset($pdoException->errorInfo[1]) && 1227 === $pdoException->errorInfo[1]) {
                return null;
            }
            throw new RepositoryException(__METHOD__ . $pdoException->getMessage(), 0, $pdoException);
        }
    }
}
