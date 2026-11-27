<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SqlClient;
use App\Repository\SqlClientRepository;
use App\RepositoryPDO\ManagedServerRepositoryFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

// Destructive (DROP DATABASE / DROP USER on the managed server): never registered in prod.
#[When(env: 'dev')]
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

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagedServerRepositoryFactory $repositories,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
                ->addArgument('arg1', InputArgument::OPTIONAL, 'Argument description')
                ->addOption('option1', mode: InputOption::VALUE_NONE, description: 'Option description')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var SqlClientRepository $sqlClientRepository */
        $sqlClientRepository = $this->entityManager->getRepository(SqlClient::class);
        $sqlClient = $sqlClientRepository->findOneByName(self::SERVER_NAME);
        if (!$sqlClient instanceof SqlClient) {
            $io->error(\sprintf('Server "%s" not found: register it with app:setup-sqlclient first.', self::SERVER_NAME));

            return Command::FAILURE;
        }

        $schema = $this->repositories->schema($sqlClient);
        $accounts = $this->repositories->accounts($sqlClient);
        $demoData = $this->repositories->demoData($sqlClient);

        // Drop all possible existing elements for testing
        $accounts->dropUser(self::DB_NAME_USER);
        $schema->dropDatabase(self::DB_NAME_TESTING_ONE);
        $schema->dropDatabase(self::DB_NAME_TESTING_TWO);

        // Create and populate DB1
        $schema->createDatabase(self::DB_NAME_TESTING_ONE);

        $accounts->createUser(self::DB_NAME_USER, self::DB_NAME_PASSWORD);
        $accounts->grantPrivileges(self::DB_NAME_TESTING_ONE, self::DB_NAME_USER);
        $accounts->flushPrivileges();

        $schema->useDbName(self::DB_NAME_TESTING_ONE);
        $demoData->createDummyTable();
        $demoData->populateDummiTable();
        $demoData->createDummyTableTwo();
        $demoData->populateDummiTableTwo();

        // Create and populate DB2
        $schema->createDatabase(self::DB_NAME_TESTING_TWO);
        $accounts->grantPrivileges(self::DB_NAME_TESTING_TWO, self::DB_NAME_USER);
        $accounts->flushPrivileges();

        $io->success('Dummy databases created successfully');

        return Command::SUCCESS;
    }
}
