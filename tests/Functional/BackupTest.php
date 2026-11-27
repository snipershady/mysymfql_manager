<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Component\DatabaseClientConnection;
use App\Controller\Schema\BackupController;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Service\BackupService;
use App\Tests\Fixtures\TestCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Backup cycle from the HTTP endpoints, on the real managed server and the test BACKUP_PATH:
 * immediate backup → listing → restore → deletion, with the visibility rules of the owner and of a
 * user who does not own the database. The test database and its dumps are removed in tearDown.
 */
#[CoversClass(BackupController::class)]
#[CoversClass(BackupService::class)]
final class BackupTest extends FunctionalTestCase
{
    private string $server;

    private string $ownedDb;

    private DatabaseSchemaRepository $remote;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['mysqldump', 'mysql'] as $binary) {
            if (null === new ExecutableFinder()->find($binary)) {
                self::markTestSkipped($binary . ' is not installed.');
            }
        }
        $this->server = self::env('TEST_SERVER_NAME');
        $this->ownedDb = self::env('TEST_DB_NAME_ONE');
        $this->remote = new DatabaseSchemaRepository($this->sqlClient($this->server));
        $this->remote->dropDatabase($this->ownedDb);
        $this->remote->createDatabase($this->ownedDb);
        $this->remote->useDbName($this->ownedDb);
        $this->remote->runQuery('CREATE TABLE cities (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL)');
        $this->remote->runQuery("INSERT INTO cities VALUES (1, 'Roma'), (2, 'Napoli')");
    }

    protected function tearDown(): void
    {
        $this->remote->dropDatabase($this->ownedDb);
        $dumpDir = \sprintf('%s/%d/%s', self::env('BACKUP_PATH'), (int) $this->sqlClient($this->server)->getId(), $this->ownedDb);
        foreach (glob($dumpDir . '/bkp_*') ?: [] as $file) {
            unlink($file);
        }
        DatabaseClientConnection::reset();
        parent::tearDown();
    }

    public function testOwnerBacksUpRestoresAndDeletesItsDatabase(): void
    {
        $this->loginAs(TestCatalog::OWNER);

        $this->schemaPost('/schema/table-backup', ['name' => $this->server, 'db_name' => $this->ownedDb]);
        self::assertResponseIsSuccessful();
        $backup = $this->json();
        $this->assertTrue($backup['is_valid'], (string) ($backup['message'] ?? ''));
        $this->assertIsString($backup['backup_filename']);
        $id = \sprintf('%d/%s/%s', (int) $this->sqlClient($this->server)->getId(), $this->ownedDb, $backup['backup_filename']);

        $this->client->request('GET', '/schema/backup-list');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $backup['backup_filename']);

        $this->remote->useDbName($this->ownedDb);
        $this->remote->runQuery('DELETE FROM cities');

        $this->schemaPost('/schema/table-restore-exec', ['name' => $this->server, 'db_name' => $this->ownedDb, 'backup_filename' => $id]);
        self::assertResponseIsSuccessful();
        $this->assertTrue($this->json()['is_valid']);
        $this->remote->useDbName($this->ownedDb);
        $this->assertSame(2, $this->remote->countTableRow('cities'));

        $this->schemaPost('/schema/backup-delete', ['filename' => $id]);
        self::assertResponseIsSuccessful();
        $this->assertTrue($this->json()['is_valid']);
        $this->assertFileDoesNotExist(self::env('BACKUP_PATH') . '/' . $id);
    }

    public function testAUserWhoDoesNotOwnTheDatabaseNeitherSeesNorDeletesItsBackups(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->schemaPost('/schema/table-backup', ['name' => $this->server, 'db_name' => $this->ownedDb]);
        $filename = $this->json()['backup_filename'];
        $this->assertIsString($filename);
        $id = \sprintf('%d/%s/%s', (int) $this->sqlClient($this->server)->getId(), $this->ownedDb, $filename);

        $this->loginAs(TestCatalog::OTHER);
        $this->client->request('GET', '/schema/backup-list');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', $filename);

        $this->client->request('GET', '/schema/backup-download', ['filename' => $id]);
        self::assertResponseStatusCodeSame(404);

        $this->schemaPost('/schema/backup-delete', ['filename' => $id]);
        self::assertResponseStatusCodeSame(404);
        $this->assertFileExists(self::env('BACKUP_PATH') . '/' . $id);
    }

    public function testFailedRestoreShowsTheNativeMysqlError(): void
    {
        $this->loginAs(TestCatalog::OWNER);
        $this->schemaPost('/schema/table-backup', ['name' => $this->server, 'db_name' => $this->ownedDb]);
        $filename = $this->json()['backup_filename'];
        $this->assertIsString($filename);
        $id = \sprintf('%d/%s/%s', (int) $this->sqlClient($this->server)->getId(), $this->ownedDb, $filename);
        file_put_contents(self::env('BACKUP_PATH') . '/' . $id, "SELEC broken;\n");

        $this->schemaPost('/schema/table-restore-exec', ['name' => $this->server, 'db_name' => $this->ownedDb, 'backup_filename' => $id]);

        self::assertResponseIsSuccessful();
        $result = $this->json();
        $this->assertFalse($result['is_valid']);
        // mysql's own message, e.g. "ERROR 1064 (42000) at line 1: You have an error in your SQL syntax…"
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('ERROR 1064 (42000)', $result['message']);
    }
}
