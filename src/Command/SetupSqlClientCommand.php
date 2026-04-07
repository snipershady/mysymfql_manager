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
    description: 'Registra un server MySQL (SqlClient) nel database applicativo',
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
            ->addArgument('name', InputArgument::REQUIRED, 'Nome identificativo del server (es. Produzione)')
            ->addArgument('host', InputArgument::REQUIRED, 'Hostname o IP del server MySQL (es. 192.168.1.10)')
            ->addArgument('username', InputArgument::REQUIRED, 'Username MySQL (es. manager)')
            ->addArgument('password', InputArgument::REQUIRED, 'Password MySQL')
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'Porta MySQL', 3306);
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
            $io->error(sprintf('Un server con host "%s" è già registrato.', $host));

            return Command::FAILURE;
        }

        if (null !== $this->sqlClientRepository->findOneByName($name)) {
            $io->error(sprintf('Un server con nome "%s" è già registrato.', $name));

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
            'Server MySQL registrato con successo: [%s] %s@%s:%d (id: %d)',
            $name,
            $username,
            $host,
            $port,
            (int) $sqlClient->getId(),
        ));

        return Command::SUCCESS;
    }
}
