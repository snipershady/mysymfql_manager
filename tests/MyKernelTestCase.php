<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
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
    protected \Symfony\Component\DependencyInjection\ContainerInterface $containerInterface;
    protected const string DB_NAME_TESTING_ONE = 'unit_testing_one';
    protected const string DB_NAME_TESTING_TWO = 'unit_testing_two';
    protected const string DB_NAME_USER = 'unit_testing_user';
    protected const string DB_NAME_PASSWORD = 'unit_testing_password';
    protected const string DUMMY_TABLE_ONE = 'jujutsu_kaisen_cast';
    protected const string DUMMY_TABLE_TWO = 'jojo_cast';
    protected const string HOST = 'localhost';
    protected const string SERVER_NAME = 'server_locale';

    #[\Override]
    public function setUp(): void
    {
        parent::setUp();

        $this->kernelInterface = self::bootKernel();
        $this->containerInterface = $this->kernelInterface->getContainer();
        $this->entityManager = $this->containerInterface
                ->get('doctrine')
                ->getManager();

        // $container = static::getContainer();

        $input = new ArgvInput();
        $output = new ConsoleOutput();
        $this->io = new SymfonyStyle($input, $output);
    }
}
