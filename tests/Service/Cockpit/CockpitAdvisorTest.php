<?php

declare(strict_types=1);

namespace App\Tests\Service\Cockpit;

use App\Enum\SeverityEnum;
use App\Service\Cockpit\CockpitAdvisor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CockpitAdvisor::class)]
final class CockpitAdvisorTest extends TestCase
{
    private const array HEALTHY_STATUS = [
        'Uptime' => '1000', 'Questions' => '50000', 'Threads_connected' => '10', 'Threads_running' => '2',
        'Max_used_connections' => '20', 'Connections' => '5000', 'Aborted_connects' => '3',
        'Innodb_buffer_pool_read_requests' => '1000000', 'Innodb_buffer_pool_reads' => '100',
        'Innodb_buffer_pool_pages_total' => '1000', 'Innodb_buffer_pool_pages_free' => '250', 'Innodb_buffer_pool_pages_dirty' => '10',
        'Created_tmp_tables' => '5000', 'Created_tmp_disk_tables' => '50',
        'Table_open_cache_hits' => '9900', 'Table_open_cache_misses' => '100',
        'Innodb_row_lock_waits' => '4', 'Innodb_row_lock_time_avg' => '12', 'Slow_queries' => '7', 'Com_select' => '30000',
    ];

    private const array HEALTHY_VARIABLES = [
        'max_connections' => '151', 'innodb_buffer_pool_size' => '134217728', 'slow_query_log' => 'ON',
        'performance_schema' => 'ON', 'innodb_flush_log_at_trx_commit' => '1', 'read_only' => 'OFF',
        'version' => '8.4.8', 'datadir' => '/var/lib/mysql/',
    ];

    public function testHealthyServerHasNoFindingsAndComputesTheIndicators(): void
    {
        $server = new CockpitAdvisor()->server(self::HEALTHY_STATUS, self::HEALTHY_VARIABLES, []);

        $this->assertSame([], $server['findings']);
        $this->assertEqualsWithDelta(0.0662, $server['gauges']['connections_usage'], \PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(0.75, $server['gauges']['buffer_pool_usage'], \PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(0.9999, $server['gauges']['buffer_pool_hit_ratio'], \PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(0.01, $server['gauges']['tmp_disk_ratio'], \PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(0.99, $server['gauges']['table_cache_hit_ratio'], \PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(50.0, $server['gauges']['questions_per_second_avg'], \PHP_FLOAT_EPSILON);
        $this->assertSame(30000, $server['counters']['Com_select']);
        $this->assertSame(0, $server['counters']['Com_delete']);
        $this->assertSame('8.4.8', $server['config']['version']);
        $this->assertArrayNotHasKey('datadir', $server['config'], 'only the listed variables');
        $this->assertSame(SeverityEnum::OK, CockpitAdvisor::worst($server['findings']));
    }

    public function testUnhealthyServer(): void
    {
        $status = [...self::HEALTHY_STATUS,
            'Threads_connected' => '140', 'Innodb_buffer_pool_reads' => '50000', 'Created_tmp_disk_tables' => '2000',
            'Aborted_connects' => '600', 'Table_open_cache_misses' => '5000', 'Innodb_row_lock_time_avg' => '2500', 'Innodb_row_lock_waits' => '40',
        ];
        $variables = [...self::HEALTHY_VARIABLES, 'slow_query_log' => 'OFF', 'performance_schema' => 'OFF', 'innodb_flush_log_at_trx_commit' => '2', 'super_read_only' => 'ON'];
        $replica = [
            ['Channel_Name' => '', 'Replica_IO_Running' => 'Yes', 'Replica_SQL_Running' => 'No', 'Last_SQL_Error' => 'Duplicate entry', 'Seconds_Behind_Source' => null],
            ['Channel_Name' => 'eu', 'Replica_IO_Running' => 'Yes', 'Replica_SQL_Running' => 'Yes', 'Seconds_Behind_Source' => 900],
        ];

        $findings = new CockpitAdvisor()->server($status, $variables, $replica)['findings'];
        $text = implode("\n", array_map(static fn (array $f): string => $f['severity']->value . ' ' . $f['message'], $findings));

        $this->assertStringContainsString('danger Connessioni al 93% di max_connections (140/151)', $text);
        $this->assertStringContainsString('warning Hit ratio del buffer pool InnoDB al 95,0%', $text);
        $this->assertStringContainsString('warning Il 40,0% delle tabelle temporanee', $text);
        $this->assertStringContainsString('warning 600 tentativi di connessione falliti (12,0%)', $text);
        $this->assertStringContainsString('info Hit ratio della table open cache', $text);
        $this->assertStringContainsString('warning Attesa media sui lock di riga di 2500 ms (40 attese)', $text);
        $this->assertStringContainsString('info slow_query_log disattivato', $text);
        $this->assertStringContainsString('info performance_schema disattivato', $text);
        $this->assertStringContainsString('info innodb_flush_log_at_trx_commit = 2', $text);
        $this->assertStringContainsString('info Server in sola lettura', $text);
        $this->assertStringContainsString('danger Replica ferma: IO Yes, SQL No. Duplicate entry', $text);
        $this->assertStringContainsString('warning Replica in ritardo di 900 secondi (canale eu)', $text);
        $this->assertSame(SeverityEnum::DANGER, CockpitAdvisor::worst($findings));
    }

    public function testPeakOfConnectionsAndEmptyServer(): void
    {
        $peak = new CockpitAdvisor()->server([...self::HEALTHY_STATUS, 'Max_used_connections' => '130'], self::HEALTHY_VARIABLES, replica: null);
        $this->assertSame(SeverityEnum::WARNING, $peak['findings'][0]['severity']);
        $this->assertStringContainsString('Picco di connessioni', $peak['findings'][0]['message']);
        $this->assertSame([], $peak['replica']);

        $empty = new CockpitAdvisor()->server([], [], replica: null)['gauges'];
        $this->assertNull($empty['connections_usage']);
        $this->assertNull($empty['buffer_pool_hit_ratio']);
        $this->assertNull($empty['questions_per_second_avg']);
    }

    public function testSchemaFindings(): void
    {
        $mb = 1_048_576;
        $tables = [
            ['table_name' => 'orders', 'engine' => 'InnoDB', 'table_rows' => 1000, 'data_bytes' => 100 * $mb, 'index_bytes' => 20 * $mb, 'free_bytes' => 80 * $mb, 'collation' => 'utf8mb4_0900_ai_ci'],
            ['table_name' => 'legacy', 'engine' => 'MyISAM', 'table_rows' => 10, 'data_bytes' => 1024, 'index_bytes' => 0, 'free_bytes' => 0, 'collation' => 'latin1_swedish_ci'],
            ['table_name' => 'log', 'engine' => 'InnoDB', 'table_rows' => null, 'data_bytes' => 16384, 'index_bytes' => 0, 'free_bytes' => 0, 'collation' => 'utf8mb4_0900_ai_ci'],
        ];
        $autoIncrement = [
            ['table_name' => 'log', 'column_name' => 'id', 'data_type' => 'tinyint', 'column_type' => 'tinyint unsigned', 'next_value' => 240],
            ['table_name' => 'orders', 'column_name' => 'id', 'data_type' => 'int', 'column_type' => 'int', 'next_value' => 1_700_000_000],
            ['table_name' => 'big', 'column_name' => 'id', 'data_type' => 'bigint', 'column_type' => 'bigint unsigned', 'next_value' => 10],
            ['table_name' => 'odd', 'column_name' => 'id', 'data_type' => 'decimal', 'column_type' => 'decimal(10,0)', 'next_value' => 10],
            ['table_name' => 'empty', 'column_name' => 'id', 'data_type' => 'int', 'column_type' => 'int', 'next_value' => null],
        ];

        $schema = new CockpitAdvisor()->schema($tables, ['log'], $autoIncrement, ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_ai_ci'], [['table_name' => 'orders', 'index_name' => 'x']], [['table_name' => 'orders', 'index_name' => 'y']]);
        $text = implode("\n", array_map(static fn (array $f): string => $f['severity']->value . ' ' . $f['message'], $schema['findings']));

        $this->assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_ai_ci', 'table_count' => 3, 'rows' => 1010, 'data_bytes' => 100 * $mb + 1024 + 16384, 'index_bytes' => 20 * $mb, 'free_bytes' => 80 * $mb, 'engines' => ['InnoDB' => 2, 'MyISAM' => 1]], $schema['summary']);
        $this->assertSame(['log', 'orders', 'big', 'empty'], array_column($schema['auto_increment'], 'table_name'), 'fullest first, unknown types skipped');
        $this->assertEqualsWithDelta(0.0, $schema['auto_increment'][3]['usage'], \PHP_FLOAT_EPSILON, 'a table without rows starts at 1');
        $this->assertEqualsWithDelta(0.9373, $schema['auto_increment'][0]['usage'], \PHP_FLOAT_EPSILON);
        $this->assertSame([['table_name' => 'orders', 'free_bytes' => 80 * $mb, 'free_ratio' => 0.4]], $schema['fragmented']);
        $this->assertStringContainsString('danger AUTO_INCREMENT di log.id (tinyint unsigned) al 93,7%', $text);
        $this->assertStringContainsString('warning AUTO_INCREMENT di orders.id (int) al 79,2%', $text);
        $this->assertStringContainsString('warning 1 tabella senza primary key (log)', $text);
        $this->assertStringContainsString('warning 1 tabelle MyISAM', $text);
        $this->assertStringContainsString('info 1 tabella con molto spazio libero non restituito (orders)', $text);
        $this->assertStringContainsString('info 1 tabella con collation diversa', $text);
        $this->assertStringContainsString('warning 1 indice ridondante', $text);
        $this->assertStringContainsString('info 1 indice mai usato', $text);
    }

    public function testEmptySchemaAndUnreadableIndexes(): void
    {
        $schema = new CockpitAdvisor()->schema([], [], [], [], unused: null, redundant: null);

        $this->assertCount(1, $schema['findings']);
        $this->assertStringContainsString('Nessuna tabella', $schema['findings'][0]['message']);
        $this->assertSame(0, $schema['summary']['table_count']);
    }
}
