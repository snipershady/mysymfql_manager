<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ProcessBackupQueueCommand;
use App\Entity\BackupQueue;
use App\Entity\SqlClient;
use App\Enum\BackupQueueStatusEnum;
use App\Repository\BackupQueueRepository;
use App\Service\MysqldumpManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The entry's SqlClient is never persisted, so any backup attempt fails fast inside
 * MysqldumpManager (no MySQL server needed): the tests check the queue state machine.
 */
#[CoversClass(ProcessBackupQueueCommand::class)]
final class ProcessBackupQueueCommandTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];

    private int $flushes = 0;

    /** @var list<array{before: \DateTime, error: string}> */
    private array $staleCalls = [];

    public function testEmptyQueueIsASuccess(): void
    {
        $tester = $this->tester(entry: null, claimed: false);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('No pending backup', $tester->getDisplay());
    }

    public function testEntryTakenByAnotherWorkerIsNotProcessed(): void
    {
        $entry = $this->entry();
        $tester = $this->tester($entry, claimed: false);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('already taken', $tester->getDisplay());
        $this->assertSame(BackupQueueStatusEnum::PENDING, $entry->getStatus());
        $this->assertSame([], $this->logged);
    }

    public function testFailedBackupIsMarkedFailedWithItsErrorAndSaved(): void
    {
        $entry = $this->entry();
        $tester = $this->tester($entry, claimed: true);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertSame(BackupQueueStatusEnum::FAILED, $entry->getStatus());
        $this->assertNull($entry->getCompletedDate());
        // The SqlClient is not persisted: MysqldumpManager refuses it, and that message is what the dashboard shows.
        $this->assertStringContainsString('persisted SqlClient', (string) $entry->getError());
        $this->assertSame(['Queued backup failed.'], $this->logged);
        $this->assertSame(1, $this->flushes);
    }

    public function testInterruptedEntriesAreMarkedFailedBeforeTakingTheNextOne(): void
    {
        $tester = $this->tester(entry: null, claimed: false, stale: 2);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--stale-after' => '6']));
        $this->assertStringContainsString('2 interrupted entries (running for more than 6 hours) marked as failed', $tester->getDisplay());
        $this->assertSame(['Backup queue: {count} interrupted entries marked as failed.'], $this->logged);
        $this->assertCount(1, $this->staleCalls);
        $this->assertStringContainsString('più di 6 ore', $this->staleCalls[0]['error']);
        $this->assertEqualsWithDelta(time() - 6 * 3600, $this->staleCalls[0]['before']->getTimestamp(), 5);
    }

    public function testDefaultStaleThresholdAndInvalidValues(): void
    {
        $this->tester(entry: null, claimed: false)->execute([]);
        $this->assertEqualsWithDelta(time() - ProcessBackupQueueCommand::DEFAULT_STALE_HOURS * 3600, $this->staleCalls[0]['before']->getTimestamp(), 5);

        foreach (['0', 'abc'] as $value) {
            $this->assertSame(Command::INVALID, $this->tester(entry: null, claimed: false)->execute(['--stale-after' => $value]));
        }
    }

    private function entry(): BackupQueue
    {
        return new BackupQueue()
            ->setSqlClient(new SqlClient()->setName('srv')->setHost('127.0.0.1')->setUsername('u')->setPassword('p'))
            ->setDbName('shop');
    }

    private function tester(?BackupQueue $entry, bool $claimed, int $stale = 0): CommandTester
    {
        $repository = $this->createStub(BackupQueueRepository::class);
        $repository->method('findOldestPending')->willReturn($entry);
        $repository->method('failStale')->willReturnCallback(function (\DateTime $before, string $error) use ($stale): int {
            $this->staleCalls[] = ['before' => $before, 'error' => $error];

            return $stale;
        });
        $repository->method('claim')->willReturnCallback(static function (BackupQueue $e) use ($claimed): bool {
            if ($claimed) {
                $e->setStatus(BackupQueueStatusEnum::RUNNING)->setAttempts($e->getAttempts() + 1);
            }

            return $claimed;
        });

        $logger = new class($this->logged) extends AbstractLogger {
            /** @param list<string> $messages */
            public function __construct(private array &$messages)
            {
            }

            #[\Override]
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        return new CommandTester(new ProcessBackupQueueCommand(
            $repository,
            new MysqldumpManager(sys_get_temp_dir() . '/process_backup_queue_test'), // never written: the dump fails before
            $entityManager,
            $logger,
        ));
    }
}
