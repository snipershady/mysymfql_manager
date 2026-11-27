<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ProcessBackupQueueCommand;
use App\Entity\BackupQueue;
use App\Entity\SqlClient;
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
        $this->assertNull($entry->getCompletedDate());
        $this->assertSame([], $this->logged);
    }

    public function testFailedBackupLeavesTheEntryOutOfTheQueueWithoutCompletionDate(): void
    {
        $entry = $this->entry();
        $tester = $this->tester($entry, claimed: true);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertTrue($entry->isDequeued());
        $this->assertNull($entry->getCompletedDate());
        $this->assertSame(['Queued backup failed.'], $this->logged);
    }

    private function entry(): BackupQueue
    {
        return new BackupQueue()
            ->setSqlClient(new SqlClient()->setName('srv')->setHost('127.0.0.1')->setUsername('u')->setPassword('p'))
            ->setDbName('shop');
    }

    private function tester(?BackupQueue $entry, bool $claimed): CommandTester
    {
        $repository = $this->createStub(BackupQueueRepository::class);
        $repository->method('findOldestPending')->willReturn($entry);
        $repository->method('claim')->willReturnCallback(static function (BackupQueue $e) use ($claimed): bool {
            if ($claimed) {
                $e->setIsDequeued(isDequeued: true);
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

        return new CommandTester(new ProcessBackupQueueCommand(
            $repository,
            new MysqldumpManager(sys_get_temp_dir() . '/process_backup_queue_test'), // never written: the dump fails before
            $this->createStub(EntityManagerInterface::class),
            $logger,
        ));
    }
}
