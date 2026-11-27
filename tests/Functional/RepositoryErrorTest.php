<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\Schema\QueryController;
use App\EventListener\JsonAction;
use App\EventListener\RepositoryErrorListener;
use App\Tests\Fixtures\TestCatalog;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A statement failing on the managed server reaches the panel user with MySQL's own message
 * (the panel is a sysadmin tool), in the JSON format the front end reads.
 * Read-only on the managed server: every statement here fails.
 */
#[CoversClass(RepositoryErrorListener::class)]
#[CoversClass(JsonAction::class)]
#[CoversClass(QueryController::class)]
final class RepositoryErrorTest extends FunctionalTestCase
{
    public function testJsonEndpointAnswersWithTheNativeMysqlError(): void
    {
        $this->loginAs(TestCatalog::ADMIN);
        $this->client->request('GET', '/schema/table-show-create', ['name' => self::env('TEST_SERVER_NAME'), 'db_name' => 'mysql', 'table' => 'no_such_table']);

        self::assertResponseStatusCodeSame(500);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $this->assertSame(
            ['is_valid' => false, 'message' => "ERROR 1146 (42S02): Table 'mysql.no_such_table' doesn't exist"],
            $this->json(),
        );
    }

    public function testQueryConsoleShowsTheNativeMysqlError(): void
    {
        $this->loginAs(TestCatalog::ADMIN);
        $token = $this->schemaCsrfToken();
        $this->client->jsonRequest('POST', '/schema/query-execute', ['name' => self::env('TEST_SERVER_NAME'), 'db_name' => 'mysql', 'sql' => 'SELEC 1'], ['HTTP_X_CSRF_TOKEN' => $token]);

        self::assertResponseStatusCodeSame(400);
        $result = $this->json();
        $this->assertFalse($result['is_valid']);
        $this->assertIsString($result['message']);
        $this->assertStringStartsWith('ERROR 1064 (42000): You have an error in your SQL syntax', $result['message']);
    }
}
