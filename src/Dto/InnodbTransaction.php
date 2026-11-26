<?php

declare(strict_types=1);

namespace App\Dto;

use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

/**
 * Represents a row from information_schema.INNODB_TRX (an open InnoDB transaction).
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @see \App\Tests\Dto\InnodbTransactionTest
 */
final readonly class InnodbTransaction
{
    /**
     * @param int    $threadId   trx_mysql_thread_id: the connection (processlist ID) owning the transaction
     * @param string $state      trx_state: RUNNING, LOCK WAIT, ROLLING BACK, COMMITTING
     * @param string $started    trx_started, as returned by the server (server time zone)
     * @param int    $rowsLocked trx_rows_locked: approximate number of locked rows
     */
    public function __construct(
        public int $threadId,
        public string $state,
        public string $started,
        public int $rowsLocked,
    ) {
    }

    /**
     * @param array<string, mixed> $row Row coming from PDO::FETCH_ASSOC
     */
    public static function fromArray(array $row): self
    {
        $epti = new EffectivePrimitiveTypeIdentifierService();

        return new self(
            threadId: $epti->getIntValueFromArray('trx_mysql_thread_id', $row),
            state: $epti->getStringValueFromArray('trx_state', $row, forceString: true),
            started: $epti->getStringValueFromArray('trx_started', $row, forceString: true),
            rowsLocked: $epti->getIntValueFromArray('trx_rows_locked', $row),
        );
    }
}
