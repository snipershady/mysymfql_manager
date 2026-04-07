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
    description: 'Esegue il backup completo di un singolo database di un server MySQL',
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
        $this->addArgument('name', InputArgument::REQUIRED, 'Nome del server MySQL (es. mithrandir_manager)');
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
            $io->error(sprintf('Nessun server configurato con name "%s".', $name));

            return Command::FAILURE;
        }

        $repo = new DatabaseSchemaRepository($sqlClient);
        $databases = $repo->getDatabasesWithStats($dbName);

        if (empty($databases)) {
            $io->warning('Nessun database trovato sul server.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Backup di %d database su %s', count($databases), $name));

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
                    $io->writeln('<fg=red>FALLITO</>');
                    if (!empty($result['output'])) {
                        $io->writeln(array_map(fn (string $l): string => '    '.$l, $result['output']));
                    }
                    ++$failure;
                }
            } catch (\Throwable $e) {
                $io->writeln(sprintf('<fg=red>ERRORE: %s</>', $e->getMessage()));
                ++$failure;
            }
        }

        $io->newLine();

        if (0 === $failure) {
            $io->success(sprintf('Tutti i %d backup completati con successo.', $success));

            return Command::SUCCESS;
        }

        if (0 === $success) {
            $io->error(sprintf('Tutti i %d backup falliti.', $failure));

            return Command::FAILURE;
        }

        $io->warning(sprintf('%d backup completati, %d falliti.', $success, $failure));

        return Command::FAILURE;
    }
}
