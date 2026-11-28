<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AuditLogRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Audit log rotation: deletes the entries older than --days (default and maximum 90: the log keeps at most
 * three months of history).
 *
 * Panel role: CLI (server administrator, cron). MySQL privileges: none on the managed servers, only DELETE on the
 * application catalog. Without --force nothing is deleted.
 *
 * @see \App\Tests\Functional\AuditPruneCommandTest
 */
#[AsCommand(name: 'app:audit-prune', description: 'Deletes the audit log entries older than N days (at most 90)', help: <<<'TXT'
Deletes the audit log entries older than --days (default 90, which is also the maximum: the audit log keeps at
most three months of history).

  php bin/console app:audit-prune                    # show how many entries would be deleted
  php bin/console app:audit-prune --force            # delete the entries older than 90 days
  php bin/console app:audit-prune --days=30 --force  # keep only the last 30 days

Cron (every night at 03:45):
  45 3 * * * /usr/bin/php /path/to/bin/console app:audit-prune --force --no-interaction
TXT)]
final class AuditPruneCommand extends Command
{
    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('days', mode: InputOption::VALUE_REQUIRED, description: \sprintf('Retention in days, from 1 to %d', AuditLogRepository::MAX_RETENTION_DAYS), default: (string) AuditLogRepository::MAX_RETENTION_DAYS)
            ->addOption('force', mode: InputOption::VALUE_NONE, description: 'Actually delete the entries (default: only show how many)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = $input->getOption('days');
        if (!ctype_digit($days) || (int) $days < 1 || (int) $days > AuditLogRepository::MAX_RETENTION_DAYS) {
            $io->error(\sprintf('--days must be an integer from 1 to %d.', AuditLogRepository::MAX_RETENTION_DAYS));

            return Command::INVALID;
        }

        $cutoff = $this->clock->now()->modify(\sprintf('-%d days', (int) $days));
        $stats = $this->repository->statsOlderThan($cutoff);
        if (0 === $stats['count']) {
            $io->success(\sprintf('No audit log entries older than %d day(s) (before %s).', (int) $days, $cutoff->format('Y-m-d H:i')));

            return Command::SUCCESS;
        }

        $summary = \sprintf('%d entr%s before %s (oldest: %s)', $stats['count'], 1 === $stats['count'] ? 'y' : 'ies', $cutoff->format('Y-m-d H:i'), $stats['oldest']?->format('Y-m-d H:i') ?? '-');
        if (true !== $input->getOption('force')) {
            $io->note($summary . ': nothing has been deleted. Re-run with --force.');

            return Command::SUCCESS;
        }

        $deleted = $this->repository->deleteOlderThan($cutoff);
        $io->success(\sprintf('%d audit log entr%s deleted (before %s).', $deleted, 1 === $deleted ? 'y' : 'ies', $cutoff->format('Y-m-d H:i')));

        return Command::SUCCESS;
    }
}
