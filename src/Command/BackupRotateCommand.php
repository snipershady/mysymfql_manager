<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\BackupDump;
use App\Service\BackupRotationPolicy;
use App\Service\MysqldumpManager;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backup rotation: deletes the dumps older than N days, keeping the most recent --keep of every series
 * (one kind of dump of one database on one server, see BackupRotationPolicy), plus incomplete ".part"
 * files older than N days left by killed dumps.
 *
 * Panel role: CLI (server administrator, cron). MySQL privileges: none, it only reads the BACKUP_PATH
 * listing (no catalog or MySQL connection). Without --force nothing is deleted. A file that cannot be
 * deleted is reported and logged, the others are still processed, and the exit code is 1.
 *
 * @see \App\Tests\Command\BackupRotateCommandTest
 */
#[AsCommand(name: 'app:backup-rotate', description: 'Deletes the backups older than N days (keeping the most recent ones of every database)', help: <<<'TXT'
Deletes the dumps under BACKUP_PATH whose modification time is older than <days>, except the --keep most recent
of every series (server + database + table or full dump), so a database whose backups stopped keeps its last dump.
Incomplete "*.sql.part" files (and "*.sql.part.zst|.gz" left by an interrupted compression) older than <days> are deleted too.
Compressed (.sql.zst, .sql.gz) and uncompressed dumps of the same database belong to the same series.

  php bin/console app:backup-rotate 30                  # show what would be deleted
  php bin/console app:backup-rotate 30 --force          # delete
  php bin/console app:backup-rotate 7 --keep=3 --force  # 7 days, at least 3 dumps per series

Cron (every night at 03:30, after the backups):
  30 3 * * * /usr/bin/php /path/to/bin/console app:backup-rotate 30 --force --no-interaction
TXT)]
final class BackupRotateCommand extends Command
{
    public function __construct(
        private readonly MysqldumpManager $mysqldumpManager,
        private readonly BackupRotationPolicy $policy,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('days', InputArgument::REQUIRED, 'Retention period in days (dumps older than this are deleted)')
            ->addOption('keep', mode: InputOption::VALUE_REQUIRED, description: 'Most recent dumps of every series always kept, whatever their age (0 = none)', default: '1')
            ->addOption('force', mode: InputOption::VALUE_NONE, description: 'Actually delete the files (default: only show the plan)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = $this->nonNegativeInt($input->getArgument('days'));
        $keep = $this->nonNegativeInt($input->getOption('keep'));
        if (null === $days || $days < 1 || null === $keep) {
            $io->error('<days> must be an integer >= 1 and --keep an integer >= 0.');

            return Command::INVALID;
        }

        $now = $this->clock->now();
        $cutoff = $now->modify(\sprintf('-%d days', $days))->getTimestamp();
        $expired = [
            ...$this->policy->expired($this->mysqldumpManager->listAllBackups(), $now, $days, $keep),
            ...array_values(array_filter($this->mysqldumpManager->listPartialDumps(), static fn (BackupDump $part): bool => $part->mtime < $cutoff)),
        ];

        if ([] === $expired) {
            $io->success(\sprintf('No backups older than %d day(s) to delete.', $days));

            return Command::SUCCESS;
        }

        $force = true === $input->getOption('force');
        $rows = [];
        $deleted = 0;
        $freed = 0;
        $failed = 0;
        foreach ($expired as $dump) {
            $result = 'would delete';
            if ($force) {
                if ($this->mysqldumpManager->deleteBackup($dump)) {
                    $result = 'deleted';
                    ++$deleted;
                    $freed += $dump->size;
                } else {
                    $result = 'error: cannot delete';
                    ++$failed;
                    $this->logger->error('Backup rotation: cannot delete {path}', ['path' => $dump->path]);
                }
            }

            $rows[] = [$dump->id, date('Y-m-d H:i', $dump->mtime), $this->bytes($dump->size), $result];
        }

        $io->table(['Backup', 'Date', 'Size', 'Result'], $rows);

        if (!$force) {
            $io->note(\sprintf('%d file(s), %s: nothing has been deleted. Re-run with --force.', \count($expired), $this->bytes(array_sum(array_column($expired, 'size')))));

            return Command::SUCCESS;
        }

        if ($failed > 0) {
            $io->error(\sprintf('%d file(s) deleted (%s freed), %d could not be deleted (check the permissions on BACKUP_PATH).', $deleted, $this->bytes($freed), $failed));

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d file(s) deleted, %s freed.', $deleted, $this->bytes($freed)));

        return Command::SUCCESS;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        return \is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    private function bytes(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        $value = (float) $size;
        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return 0 === $unit ? $size . ' B' : \sprintf('%.1f %s', $value, $units[$unit]);
    }
}
