<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\BackupDump;
use App\Dto\BackupOutcome;
use App\Entity\AppUser;
use App\Entity\BackupQueue;
use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
use App\Enum\BackupQueueStatusEnum;
use App\Repository\BackupQueueRepository;
use App\Repository\DatabaseOwnerRepository;
use App\Service\BackupService;
use App\Service\MysqldumpManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Runs on a temporary BACKUP_PATH with real files; backups and restores point at a closed port, so the
 * failure paths need the mysqldump/mysql binaries but no MySQL server (success paths: functional BackupTest).
 */
#[CoversClass(BackupService::class)]
#[UsesClass(BackupOutcome::class)]
#[UsesClass(MysqldumpManager::class)]
#[UsesClass(BackupDump::class)]
final class BackupServiceTest extends TestCase
{
    private string $backupDir;

    /** @var list<array{string, array<string, mixed>}> */
    private array $logged = [];

    /** @var list<string> */
    private array $catalogCalls = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->backupDir = sys_get_temp_dir() . '/backup_service_test_' . bin2hex(random_bytes(6));
        mkdir($this->backupDir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->backupDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $this->assertInstanceOf(\SplFileInfo::class, $item);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->backupDir);
    }

    public function testAdminSeesEveryBackupIncludingLegacyOnes(): void
    {
        $this->writeDump('7/shop/bkp_2026-11-27_10-00-00_shop_full.sql');
        $this->writeDump('8/crm/bkp_2026-11-27_10-00-00_crm_full.sql');
        $this->writeDump('bkp_2024-01-01_00-00-00_legacy_full.sql');

        $ids = array_map(static fn (BackupDump $d): string => $d->id, $this->service()->visibleBackups($this->admin()));

        $this->assertEqualsCanonicalizing([
            '7/shop/bkp_2026-11-27_10-00-00_shop_full.sql',
            '8/crm/bkp_2026-11-27_10-00-00_crm_full.sql',
            'bkp_2024-01-01_00-00-00_legacy_full.sql',
        ], $ids);
    }

    public function testOwnerFindsOnlyBackupsOfOwnedDatabases(): void
    {
        $this->writeDump('7/shop/bkp_2026-11-27_10-00-00_shop_full.sql');
        $this->writeDump('8/crm/bkp_2026-11-27_10-00-00_crm_full.sql');
        $service = $this->service(owned: [[7, 'shop']]);
        $owner = new AppUser()->setRoles(['ROLE_USER']);

        $this->assertSame(['7/shop/bkp_2026-11-27_10-00-00_shop_full.sql'], array_map(static fn (BackupDump $d): string => $d->id, $service->visibleBackups($owner)));
        $this->assertInstanceOf(BackupDump::class, $service->findVisible($owner, '7/shop/bkp_2026-11-27_10-00-00_shop_full.sql'));
        $this->assertNull($service->findVisible($owner, '8/crm/bkp_2026-11-27_10-00-00_crm_full.sql'));
        $this->assertNull($service->findVisible($owner, '../etc/passwd'));
    }

    public function testContentsAndDelete(): void
    {
        $path = $this->writeDump('7/shop/bkp_2026-11-27_10-00-00_shop_full.sql');
        $service = $this->service();
        $dump = $service->findVisible($this->admin(), '7/shop/bkp_2026-11-27_10-00-00_shop_full.sql');
        $this->assertInstanceOf(BackupDump::class, $dump);

        $this->assertSame('-- dump', $service->contents($dump));
        $this->assertTrue($service->delete($dump));
        $this->assertFileDoesNotExist($path);
        $this->assertSame([], $this->logged);
    }

    public function testEnqueuePersistsTheRequest(): void
    {
        $user = $this->admin();
        $sqlClient = $this->sqlClient();

        $entry = $this->service()->enqueue($user, $sqlClient, 'shop', 'orders');

        $this->assertSame(['persist', 'flush'], $this->catalogCalls);
        $this->assertSame('shop', $entry->getDbName());
        $this->assertSame('orders', $entry->getTable());
        $this->assertSame($user, $entry->getOwner());
        $this->assertSame($sqlClient, $entry->getSqlClient());
        $this->assertSame(BackupQueueStatusEnum::PENDING, $entry->getStatus());
    }

    public function testQueueEntriesAreVisibleToAdminsAndToTheOwnersOfTheirDatabase(): void
    {
        $entry = new BackupQueue()->setSqlClient($this->sqlClient(7))->setDbName('shop');
        $owner = new AppUser()->setRoles(['ROLE_USER']);

        $this->assertSame($entry, $this->service(queue: [42 => $entry])->findVisibleQueueEntry($this->admin(), 42));
        $this->assertSame($entry, $this->service(owned: [[7, 'shop']], queue: [42 => $entry])->findVisibleQueueEntry($owner, 42));
        $this->assertNull($this->service(owned: [[7, 'crm'], [8, 'shop']], queue: [42 => $entry])->findVisibleQueueEntry($owner, 42), 'same name on another server, or another database');
        $this->assertNull($this->service(queue: [42 => $entry])->findVisibleQueueEntry($this->admin(), 43), 'unknown id');
    }

    public function testFailedBackupReturnsAndLogsTheNativeError(): void
    {
        $this->requireBinary('mysqldump');

        $outcome = $this->service()->backupNow($this->sqlClient(), 'shop', table: null);

        $this->assertFalse($outcome->ok);
        // e.g. "mysqldump: Got error: 2003: Can't connect to MySQL server on '127.0.0.1:1' (111) when trying to connect"
        $this->assertStringContainsString('2003', $outcome->error);
        $this->assertStringStartsWith('bkp_', $outcome->filename);
        $this->assertCount(1, $this->logged);
        $this->assertSame('Backup failed: {error}', $this->logged[0][0]);
        $this->assertSame('srv', $this->logged[0][1]['server']);
    }

    public function testFailedRestoreReturnsAndLogsTheNativeError(): void
    {
        $this->requireBinary('mysql');
        $this->writeDump('7/shop/bkp_2026-11-27_10-00-00_shop_full.sql');
        $service = $this->service();
        $dump = $service->findVisible($this->admin(), '7/shop/bkp_2026-11-27_10-00-00_shop_full.sql');
        $this->assertInstanceOf(BackupDump::class, $dump);

        $outcome = $service->restore($this->sqlClient(), 'shop', $dump);

        $this->assertFalse($outcome->ok);
        $this->assertStringContainsString('2003', $outcome->error);
        $this->assertSame('bkp_2026-11-27_10-00-00_shop_full.sql', $outcome->filename);
        $this->assertSame('Restore failed: {error}', $this->logged[0][0] ?? null);
    }

    /**
     * @param list<array{int, string}> $owned (server id, database) pairs owned by the user
     * @param array<int, BackupQueue>  $queue queue entries by id
     */
    private function service(array $owned = [], array $queue = []): BackupService
    {
        $queueRepository = $this->createStub(BackupQueueRepository::class);
        $queueRepository->method('find')->willReturnCallback(static fn (mixed $id): ?BackupQueue => \is_int($id) ? ($queue[$id] ?? null) : null);

        $owners = $this->createStub(DatabaseOwnerRepository::class);
        $owners->method('findAllByOwner')->willReturn(array_map(
            fn (array $pair): DatabaseOwner => new DatabaseOwner()->setDbName($pair[1])->setSqlClient($this->sqlClient($pair[0])),
            $owned,
        ));

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->assertInstanceOf(BackupQueue::class, $entity);
            $this->catalogCalls[] = 'persist';
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            $this->catalogCalls[] = 'flush';
        });

        $logger = new class($this->logged) extends AbstractLogger {
            /** @param list<array{string, array<string, mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            #[\Override]
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };

        return new BackupService(new MysqldumpManager($this->backupDir), $owners, $queueRepository, $entityManager, $logger);
    }

    private function admin(): AppUser
    {
        return new AppUser()->setRoles(['ROLE_ADMIN']);
    }

    /**
     * A persisted SqlClient pointing at a closed port: every client command fails to connect.
     */
    private function sqlClient(int $id = 7): SqlClient
    {
        $sqlClient = new SqlClient()->setName('srv')->setHost('127.0.0.1')->setPort(1)->setUsername('u')->setPassword('p');
        new \ReflectionProperty(SqlClient::class, 'id')->setValue($sqlClient, $id);

        return $sqlClient;
    }

    private function writeDump(string $relativePath): string
    {
        $path = $this->backupDir . '/' . $relativePath;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0777, recursive: true);
        }
        file_put_contents($path, '-- dump');

        return $path;
    }

    private function requireBinary(string $binary): void
    {
        if (null === new ExecutableFinder()->find($binary)) {
            self::markTestSkipped($binary . ' is not installed.');
        }
    }
}
