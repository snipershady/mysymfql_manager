<?php

namespace App\Command;

use App\Repository\BackupQueueRepository;
use App\Service\MysqldumpManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $entry = $this->backupQueueRepository->findLastOneDequeable();

        if (null === $entry) {
            $io->writeln('No pending backup in queue.');

            return Command::SUCCESS;
        }

        $sqlClient = $entry->getSqlClient();
        $dbName = $entry->getDbName();
        $table = $entry->getTable();

        $io->write(sprintf(
            'Backup <info>%s</info>%s on <info>%s</info> ... ',
            $dbName,
            $table ? sprintf('.<info>%s</info>', $table) : '',
            $sqlClient->getName(),
        ));

        try {
            $result = $this->mysqldumpManager->createBackup($sqlClient, $dbName, $table);

            if ($result['is_valid']) {
                $io->writeln(sprintf('<fg=green>OK</> <fg=gray>(%s)</>', basename((string) $result['backup_filename'])));
            } else {
                $io->writeln('<fg=red>FAILED</>');
                if (!empty($result['output'])) {
                    $io->writeln(array_map(fn (string $l): string => '    '.$l, $result['output']));
                }

                return Command::FAILURE;
            }
        } catch (\Throwable $throwable) {
            $io->writeln(sprintf('<fg=red>ERROR: %s</>', $throwable->getMessage()));

            return Command::FAILURE;
        }

        $entry->setIsDequeued(true);
        $entry->setCompletedDate(new \DateTime());

        $this->entityManager->flush();

        return Command::SUCCESS;
    }
}
