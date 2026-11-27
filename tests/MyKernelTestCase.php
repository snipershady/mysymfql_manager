<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\MysqldumpManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Description of MyKernelTestCase.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
class MyKernelTestCase extends KernelTestCase
{
    protected HttpClientInterface $client;
    protected KernelInterface $kernelInterface;
    protected EntityManagerInterface $entityManager;
    public ManagerRegistry $managerRegistry;
    protected SymfonyStyle $io;
    protected ContainerInterface $containerInterface;
    protected string $dbNameTestingOne;
    protected string $dbNameTestingTwo;
    protected string $dbNameUser;
    protected string $dbNamePassword;
    protected string $dummyTableOne;
    protected string $dummyTableTwo;
    protected string $host;
    protected string $serverName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kernelInterface = self::bootKernel();
        $this->containerInterface = $this->kernelInterface->getContainer();
        $this->entityManager = $this->containerInterface
                ->get('doctrine')
                ->getManager();
        $this->dbNameTestingOne = $_ENV['TEST_DB_NAME_ONE'] ?? 'unit_testing_one';
        $this->dbNameTestingTwo = $_ENV['TEST_DB_NAME_TWO'] ?? 'unit_testing_two';
        $this->dbNameUser = $_ENV['TEST_DB_USER'] ?? 'unit_testing_user';
        $this->dbNamePassword = $_ENV['TEST_DB_PASSWORD'] ?? '';
        // The suite CREATEs and DROPs TEST_DB_USER@'%': refuse privileged or password-less accounts.
        if (\in_array($this->dbNameUser, ['root', $_ENV['TEST_SERVER_USER'] ?? 'root', $_ENV['APP_DB_USER'] ?? 'root'], strict: true) || '' === $this->dbNamePassword) {
            self::fail('TEST_DB_USER must be a dedicated throwaway account (not root / TEST_SERVER_USER / APP_DB_USER) with a non-empty TEST_DB_PASSWORD.');
        }
        $this->dummyTableOne = $_ENV['TEST_DUMMY_TABLE_ONE'] ?? 'jujutsu_kaisen_cast';
        $this->dummyTableTwo = $_ENV['TEST_DUMMY_TABLE_TWO'] ?? 'jojo_cast';
        $this->host = $_ENV['TEST_DB_HOST'] ?? 'localhost';
        $this->serverName = $_ENV['TEST_SERVER_NAME'] ?? 'localhost';
        $input = new ArgvInput();
        $output = new ConsoleOutput();
        $this->io = new SymfonyStyle($input, $output);
    }

    /**
     * The container's service, configured with the test BACKUP_PATH (.env.test).
     */
    protected function mysqldumpManager(): MysqldumpManager
    {
        $manager = self::getContainer()->get(MysqldumpManager::class);
        $this->assertInstanceOf(MysqldumpManager::class, $manager);

        return $manager;
    }
}
