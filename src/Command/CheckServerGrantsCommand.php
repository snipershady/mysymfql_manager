<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SqlClient;
use App\Repository\SqlClientRepository;
use App\Security\Grants\GrantFindingSeverityEnum;
use App\Service\ServerGrantAudit;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI version of the server grant check (/admin/server-grants), for cron or monitoring: exit code 1 when a server
 * has a "danger" finding or cannot be checked.
 *
 * Panel role: CLI (server administrator). MySQL privileges: none (SHOW GRANTS of each server account). Read only.
 *
 * @see \App\Tests\Functional\ServerGrantAuditTest
 */
#[AsCommand(name: 'app:check-server-grants', description: 'Checks that each server account is granted only on the databases of its users', help: <<<'TXT'
For every registered server (or only <name>), reads the grants of its MySQL account (SHOW GRANTS) and compares them
with the databases assigned in the panel. Exit code 1 when a server has a "danger" finding (global privileges,
wildcard grants, grants on unassigned databases, an account shared by users with different databases) or cannot
be reached.

  php bin/console app:check-server-grants
  php bin/console app:check-server-grants server_locale
TXT)]
final class CheckServerGrantsCommand extends Command
{
    public function __construct(
        private readonly ServerGrantAudit $audit,
        private readonly SqlClientRepository $sqlClientRepository,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'Name of the server (SqlClient) to check; all when omitted');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        if (\is_string($name)) {
            $sqlClient = $this->sqlClientRepository->findOneByName($name);
            if (!$sqlClient instanceof SqlClient) {
                $io->error(\sprintf('Server "%s" not found.', $name));

                return Command::INVALID;
            }

            $reports = [$this->audit->check($sqlClient)];
        } else {
            $reports = $this->audit->checkAll();
        }

        $failed = false;
        foreach ($reports as $report) {
            $severity = $report->severity();
            $failed = $failed || GrantFindingSeverityEnum::DANGER === $severity;
            $io->section(\sprintf('%s %s — %s', $report->serverName, $report->account ?? '', strtoupper($severity->value)));
            if (null !== $report->error) {
                $io->writeln('  <fg=red>' . $report->error . '</>');

                continue;
            }

            foreach ($report->findings as $finding) {
                $io->writeln(\sprintf('  [%s] %s', $finding['severity']->value, $finding['message']));
            }
        }

        if ([] === $reports) {
            $io->note('No servers registered.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
