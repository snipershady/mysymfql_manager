<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BackupQueue;
use App\Entity\SqlClient;
use App\Repository\BackupQueueRepository;
use App\Service\MysqldumpManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Processes one queue entry per run (newest first).
 *
 * Entry states (no schema change): pending = !isDequeued; done = isDequeued && completedDate;
 * failed or interrupted = isDequeued && completedDate === null (logged, never retried automatically).
 *
 * @see \App\Tests\Command\ProcessBackupQueueCommandTest
 */
#[AsCommand(
    name: 'app:process-backup-queue',
    description: 'Processes one pending entry from the backup queue',
)]
class ProcessBackupQueueCommand extends Command
{
    public function __construct(
        private readonly BackupQueueRepository $backupQueueRepository,
        private readonly MysqldumpManager $mysqldumpManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $entry = $this->backupQueueRepository->findLastOneDequeable();

        if (!$entry instanceof BackupQueue) {
            $io->writeln('No pending backup in queue.');

            return Command::SUCCESS;
        }

        // Atomic claim: an overlapping run that picked the same entry gets false and stops here.
        // From now on the entry is out of the queue whatever happens, so a failing entry can no
        // longer block the queue: completedDate stays null for failed (or interrupted) runs.
        if (!$this->backupQueueRepository->claim($entry)) {
            $io->writeln('Entry already taken by another worker.');

            return Command::SUCCESS;
        }

        $sqlClient = $entry->getSqlClient();
        $dbName = $entry->getDbName();
        $table = $entry->getTable();
        if (!$sqlClient instanceof SqlClient || null === $dbName) {
            // Claimed but unusable (should not happen: both columns are NOT NULL): leave it as failed.
            $io->writeln('<fg=red>Invalid queue entry: missing server or database.</>');
            $this->logger->error('Invalid backup queue entry.', ['entry' => $entry->getId()]);

            return Command::FAILURE;
        }

        $io->write(\sprintf(
            'Backup <info>%s</info>%s on <info>%s</info> ... ',
            $dbName,
            $table ? \sprintf('.<info>%s</info>', $table) : '',
            $sqlClient->getName(),
        ));

        try {
            $result = $this->mysqldumpManager->createBackup($sqlClient, $dbName, $table);

            if ($result['is_valid']) {
                $io->writeln(\sprintf('<fg=green>OK</> <fg=gray>(%s)</>', basename((string) $result['backup_filename'])));
            } else {
                $io->writeln('<fg=red>FAILED</>');
                if (!empty($result['output'])) {
                    $io->writeln(array_map(static fn (string $l): string => '    ' . $l, $result['output']));
                }

                $this->logger->error('Queued backup failed.', ['entry' => $entry->getId(), 'output' => $result['output']]);

                return Command::FAILURE;
            }
        } catch (\Throwable $throwable) {
            $io->writeln(\sprintf('<fg=red>ERROR: %s</>', $throwable->getMessage()));
            $this->logger->error('Queued backup failed.', ['entry' => $entry->getId(), 'exception' => $throwable]);

            return Command::FAILURE;
        }

        $entry->setCompletedDate(new \DateTime());

        $this->entityManager->flush();

        return Command::SUCCESS;
    }
}
