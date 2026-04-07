<?php

namespace App\Command;

use App\Entity\SqlClient;
use App\Repository\SqlClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:setup-sqlclient',
    description: 'Registers a MySQL server (SqlClient) in the application database',
)]
class SetupSqlClientCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SqlClientRepository $sqlClientRepository,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Server identifier name (e.g. Production)')
            ->addArgument('host', InputArgument::REQUIRED, 'MySQL server hostname or IP (e.g. 192.168.1.10)')
            ->addArgument('username', InputArgument::REQUIRED, 'MySQL username (e.g. manager)')
            ->addArgument('password', InputArgument::REQUIRED, 'MySQL password')
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'MySQL port', 3306);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = trim((string) $input->getArgument('name'));
        $host = trim((string) $input->getArgument('host'));
        $username = trim((string) $input->getArgument('username'));
        $password = (string) $input->getArgument('password');
        $port = (int) $input->getOption('port');

        if (null !== $this->sqlClientRepository->findOneBy(['host' => $host])) {
            $io->error(sprintf('A server with host "%s" is already registered.', $host));

            return Command::FAILURE;
        }

        if (null !== $this->sqlClientRepository->findOneByName($name)) {
            $io->error(sprintf('A server with name "%s" is already registered.', $name));

            return Command::FAILURE;
        }

        $sqlClient = new SqlClient();
        $sqlClient->setName($name);
        $sqlClient->setHost($host);
        $sqlClient->setUsername($username);
        $sqlClient->setPassword($password);
        $sqlClient->setPort($port);

        $this->entityManager->persist($sqlClient);
        $this->entityManager->flush();

        $io->success(sprintf(
            'MySQL server registered successfully: [%s] %s@%s:%d (id: %d)',
            $name,
            $username,
            $host,
            $port,
            (int) $sqlClient->getId(),
        ));

        return Command::SUCCESS;
    }
}
