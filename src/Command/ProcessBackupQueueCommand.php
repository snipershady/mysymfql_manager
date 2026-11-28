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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Processes one queue entry per run, oldest first (FIFO).
 *
 * The entry goes pending → running (atomic claim) → done, or failed with the native error output shown in the
 * dashboard (also logged). Failed entries are never retried automatically: a user puts them back from the
 * dashboard ("Riprova").
 *
 * An entry left "running" by a killed worker (OOM, reboot) would stay so forever: every run first marks as failed
 * the entries running for more than --stale-after hours (default 12, longer than any expected dump), so they get
 * the retry button. If such a dump was in fact still running and completes later, its entry ends up "done".
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

    public const int DEFAULT_STALE_HOURS = 12;

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('stale-after', mode: InputOption::VALUE_REQUIRED, description: 'Hours after which an entry still running is considered interrupted and marked failed', default: (string) self::DEFAULT_STALE_HOURS);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $staleAfter = $input->getOption('stale-after');
        if (!ctype_digit($staleAfter) || (int) $staleAfter < 1) {
            $io->error('--stale-after must be an integer >= 1 (hours).');

            return Command::INVALID;
        }

        $stale = $this->backupQueueRepository->failStale(
            new \DateTime(\sprintf('-%d hours', (int) $staleAfter)),
            \sprintf('Interrotto: l\'esecuzione è rimasta in corso per più di %d ore (worker terminato, riavvio). Usa "Riprova" per rimetterlo in coda.', (int) $staleAfter),
        );
        if ($stale > 0) {
            $io->writeln(\sprintf('<fg=yellow>%d interrupted entr%s (running for more than %d hours) marked as failed.</>', $stale, 1 === $stale ? 'y' : 'ies', (int) $staleAfter));
            $this->logger->warning('Backup queue: {count} interrupted entries marked as failed.', ['count' => $stale, 'stale_after_hours' => (int) $staleAfter]);
        }

        $entry = $this->backupQueueRepository->findOldestPending();

        if (!$entry instanceof BackupQueue) {
            $io->writeln('No pending backup in queue.');

            return Command::SUCCESS;
        }

        // Atomic claim: an overlapping run that picked the same entry gets false and stops here.
        // From now on the entry is out of the queue whatever happens, so a failing entry never blocks the queue.
        if (!$this->backupQueueRepository->claim($entry, new \DateTime())) {
            $io->writeln('Entry already taken by another worker.');

            return Command::SUCCESS;
        }

        $sqlClient = $entry->getSqlClient();
        $dbName = $entry->getDbName();
        $table = $entry->getTable();
        if (!$sqlClient instanceof SqlClient || null === $dbName) {
            // Claimed but unusable (should not happen: both columns are NOT NULL).
            $io->writeln('<fg=red>Invalid queue entry: missing server or database.</>');
            $this->logger->error('Invalid backup queue entry.', ['entry' => $entry->getId()]);

            return $this->fail($entry, 'Richiesta non valida: server o database mancante.');
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
                if (isset($result['warning'])) {
                    $io->writeln('    <fg=yellow>' . $result['warning'] . '</>');
                }
            } else {
                $io->writeln('<fg=red>FAILED</>');
                if (!empty($result['output'])) {
                    $io->writeln(array_map(static fn (string $l): string => '    ' . $l, $result['output']));
                }

                $this->logger->error('Queued backup failed.', ['entry' => $entry->getId(), 'output' => $result['output']]);

                return $this->fail($entry, $result['msg']);
            }
        } catch (\Throwable $throwable) {
            $io->writeln(\sprintf('<fg=red>ERROR: %s</>', $throwable->getMessage()));
            $this->logger->error('Queued backup failed.', ['entry' => $entry->getId(), 'exception' => $throwable]);

            return $this->fail($entry, $throwable->getMessage());
        }

        $entry->markDone(new \DateTime());
        $this->entityManager->flush();

        return Command::SUCCESS;
    }

    private function fail(BackupQueue $entry, string $error): int
    {
        $entry->markFailed($error);
        $this->entityManager->flush();

        return Command::FAILURE;
    }
}
