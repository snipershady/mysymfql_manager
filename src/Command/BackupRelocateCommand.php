<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SqlClient;
use App\Repository\SqlClientRepository;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Service\LegacyBackupNameResolver;
use App\Service\MysqldumpManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Moves legacy flat dumps (BACKUP_PATH/bkp_*.sql, visible to admins only) into the per-server layout
 * BACKUP_PATH/{sqlClientId}/{dbName}/, so the owners of each database see them again.
 *
 * Panel role: CLI (server administrator). MySQL privileges: SHOW DATABASES and access to the tables
 * of the candidate databases (only to break ambiguous names). Without --force nothing is moved.
 * Files whose name matches no database, or more than one, are left where they are and reported.
 */
#[AsCommand(name: 'app:backup-relocate', description: 'Moves legacy backups (BACKUP_PATH/bkp_*.sql) into the per-server/database folders', help: <<<'TXT'
Legacy dumps were written flat in BACKUP_PATH and cannot be attributed to a server, so only admins see them.
This command assigns them to the server given as argument, matching each file name against the databases
that exist on that server (plus --db). Ambiguous names are resolved by checking which table exists;
files that still match zero or several databases are left untouched.

  php bin/console app:backup-relocate production            # show the plan
  php bin/console app:backup-relocate production --force    # move the files
TXT)]
final class BackupRelocateCommand extends Command
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
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Name of the SqlClient the legacy dumps were taken from')
            ->addOption('db', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, description: 'Extra database name to match (e.g. a database dropped since the dump)')
            ->addOption('force', mode: InputOption::VALUE_NONE, description: 'Actually move the files (default: only show the plan)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        $sqlClient = $this->sqlClientRepository->findOneByName($name);
        if (!$sqlClient instanceof SqlClient) {
            $io->error(\sprintf('Server "%s" not found.', $name));

            return Command::FAILURE;
        }

        $legacy = $this->mysqldumpManager->listLegacyBackups();
        if ([] === $legacy) {
            $io->success('No legacy backups to relocate.');

            return Command::SUCCESS;
        }

        $remote = new DatabaseSchemaRepository($sqlClient);
        $extraDbs = $input->getOption('db');
        $existingDbs = $remote->showDatabases();
        $knownDbs = [...$existingDbs, ...array_values($extraDbs)];
        /** @var array<string, list<string>> $tablesByDb */
        $tablesByDb = [];
        $tableExists = static function (string $db, string $table) use ($remote, $existingDbs, &$tablesByDb): bool {
            if (!\in_array($db, $existingDbs, strict: true)) {
                return false;
            }
            if (!isset($tablesByDb[$db])) {
                $remote->useDbName($db);
                $tablesByDb[$db] = $remote->showTables();
            }

            return \in_array($table, $tablesByDb[$db], strict: true);
        };

        $force = true === $input->getOption('force');
        $rows = [];
        $moved = 0;
        $skipped = 0;
        foreach ($legacy as $dump) {
            $readings = LegacyBackupNameResolver::resolve($dump->filename, $knownDbs, $tableExists);
            if (1 !== \count($readings)) {
                ++$skipped;
                $rows[] = [$dump->filename, '-', [] === $readings ? 'no matching database' : 'ambiguous: ' . implode(', ', array_column($readings, 'db'))];

                continue;
            }

            $db = $readings[0]['db'];
            $target = \sprintf('%d/%s/', $sqlClient->getId(), $db);
            if (!$force) {
                $rows[] = [$dump->filename, $target, 'would move'];

                continue;
            }

            try {
                $this->mysqldumpManager->relocateLegacyBackup($dump, $sqlClient, $db);
                ++$moved;
                $rows[] = [$dump->filename, $target, 'moved'];
            } catch (\RuntimeException|\InvalidArgumentException $exception) {
                ++$skipped;
                $rows[] = [$dump->filename, $target, 'error: ' . $exception->getMessage()];
            }
        }

        $io->table(['Legacy file', 'Destination', 'Result'], $rows);

        if (!$force) {
            $io->note('Nothing has been moved. Check the plan, then re-run with --force.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('%d file(s) moved, %d left in place.', $moved, $skipped));

        return Command::SUCCESS;
    }
}
