<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\InnodbTransaction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InnodbTransaction::class)]
final class InnodbTransactionTest extends TestCase
{
    public function testFromArrayMapsTheInnodbTrxColumns(): void
    {
        $trx = InnodbTransaction::fromArray([
            'trx_mysql_thread_id' => '42',
            'trx_state' => 'LOCK WAIT',
            'trx_started' => '2026-11-26 23:10:05',
            'trx_rows_locked' => 7,
        ]);

        $this->assertSame(42, $trx->threadId);
        $this->assertSame('LOCK WAIT', $trx->state);
        $this->assertSame('2026-11-26 23:10:05', $trx->started);
        $this->assertSame(7, $trx->rowsLocked);
    }

    public function testMissingColumnsFallBackToNeutralValues(): void
    {
        $trx = InnodbTransaction::fromArray([]);

        $this->assertSame(0, $trx->threadId);
        $this->assertSame('', $trx->state);
        $this->assertSame('', $trx->started);
        $this->assertSame(0, $trx->rowsLocked);
    }

    public function testSerializesWithStablePropertyNames(): void
    {
        $json = json_encode(new InnodbTransaction(1, 'RUNNING', '2026-11-26 23:10:05', 0), \JSON_THROW_ON_ERROR);

        $this->assertSame('{"threadId":1,"state":"RUNNING","started":"2026-11-26 23:10:05","rowsLocked":0}', $json);
    }
}
