<?php

namespace App\Command;

use App\Repository\SqlClientRepository;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Service\MysqldumpManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backup-single',
    description: 'Performs a full backup of a single database on a MySQL server',
)]
class BackupSingleCommand extends Command
{
    public function __construct(
        private readonly SqlClientRepository $sqlClientRepository,
        private readonly MysqldumpManager $mysqldumpManager,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'MySQL server name (e.g. mithrandir_manager)');
        $this->addArgument('db_name', InputArgument::REQUIRED, 'unit_testing_db_one');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = trim((string) $input->getArgument('name'));
        $dbName = trim((string) $input->getArgument('db_name'));

        $sqlClient = $this->sqlClientRepository->findOneByName($name);
        if (null === $sqlClient) {
            $io->error(sprintf('No server configured with name "%s".', $name));

            return Command::FAILURE;
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $databases = $repo->getDatabasesWithStats($dbName);

        if (empty($databases)) {
            $io->warning('No databases found on the server.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Backup of %d database(s) on %s', count($databases), $name));

        $success = 0;
        $failure = 0;

        foreach ($databases as $database) {
            $dbName = $database['db_name'];
            $io->write(sprintf('  <info>%s</info> ... ', $dbName));

            try {
                $result = $this->mysqldumpManager->createBackup($sqlClient, $dbName);

                if ($result['is_valid']) {
                    $io->writeln(sprintf('<fg=green>OK</> <fg=gray>(%s)</>', basename((string) $result['backup_filename'])));
                    ++$success;
                } else {
                    $io->writeln('<fg=red>FAILED</>');
                    if (!empty($result['output'])) {
                        $io->writeln(array_map(fn (string $l): string => '    '.$l, $result['output']));
                    }
                    ++$failure;
                }
            } catch (\Throwable $e) {
                $io->writeln(sprintf('<fg=red>ERROR: %s</>', $e->getMessage()));
                ++$failure;
            }
        }

        $io->newLine();

        if (0 === $failure) {
            $io->success(sprintf('All %d backup(s) completed successfully.', $success));

            return Command::SUCCESS;
        }

        if (0 === $success) {
            $io->error(sprintf('All %d backup(s) failed.', $failure));

            return Command::FAILURE;
        }

        $io->warning(sprintf('%d backup(s) completed, %d failed.', $success, $failure));

        return Command::FAILURE;
    }
}
