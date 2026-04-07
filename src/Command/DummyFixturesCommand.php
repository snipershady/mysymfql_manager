<?php

namespace App\Command;

use App\Entity\SqlClient;
use App\RepositoryPDO\DatabaseSchemaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'dummy-fixtures',
    description: 'Creates two databases and populates them with test data',
)]
class DummyFixturesCommand extends Command
{
    private const string DB_NAME_TESTING_ONE = 'unit_testing_one';
    private const string DB_NAME_TESTING_TWO = 'unit_testing_two';
    private const string DB_NAME_USER = 'unit_testing_user';
    private const string DB_NAME_PASSWORD = 'unit_testing_password';
    private const string SERVER_NAME = 'server_locale';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
                ->addArgument('arg1', InputArgument::OPTIONAL, 'Argument description')
                ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var \App\Repository\SqlClientRepository $sqlClientRepository */
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName(self::SERVER_NAME);

        $databaseRepositoryPdo = new DatabaseSchemaRepository($sqlClient);

        // Drop all possible existing elements for testing
        $databaseRepositoryPdo->dropUser(self::DB_NAME_USER);
        $databaseRepositoryPdo->dropDatabase(self::DB_NAME_TESTING_ONE);
        $databaseRepositoryPdo->dropDatabase(self::DB_NAME_TESTING_TWO);

        // Create and populate DB1
        $databaseRepositoryPdo->createDatabase(self::DB_NAME_TESTING_ONE);
        $databaseRepositoryPdo->createUser(self::DB_NAME_USER, self::DB_NAME_PASSWORD);
        $databaseRepositoryPdo->grantPrivileges(self::DB_NAME_TESTING_ONE, self::DB_NAME_USER);
        $databaseRepositoryPdo->flushPrivileges();
        $databaseRepositoryPdo->useDbName(self::DB_NAME_TESTING_ONE);
        $databaseRepositoryPdo->createDummyTable();
        $databaseRepositoryPdo->populateDummiTable();
        $databaseRepositoryPdo->createDummyTableTwo();
        $databaseRepositoryPdo->populateDummiTableTwo();

        // Create and populate DB2
        $databaseRepositoryPdo->createDatabase(self::DB_NAME_TESTING_TWO);
        $databaseRepositoryPdo->grantPrivileges(self::DB_NAME_TESTING_TWO, self::DB_NAME_USER);
        $databaseRepositoryPdo->flushPrivileges();

        $io->success('Dummy databases created successfully');

        return Command::SUCCESS;
    }
}
