<?php

declare(strict_types=1);

/*
 * Prepares the browser smoke test (tests/browser/run.sh): rebuilds the test catalog with its fixtures
 * and creates, on the managed MySQL server, the demo database the pages show ("up"), or drops it ("down").
 */
use App\Entity\SqlClient;
use App\Kernel;
use App\RepositoryPDO\DatabaseSchemaRepository;
use App\Tests\Fixtures\TestCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
new Dotenv()->bootEnv(dirname(__DIR__, 2) . '/.env');

$kernel = new Kernel('test', debug: false);
$kernel->boot();
$action = $argv[1] ?? 'up';
if ('up' === $action) {
    TestCatalog::rebuild($kernel);
}

$container = $kernel->getContainer()->get('test.service_container');
assert($container instanceof ContainerInterface);
$entityManager = $container->get(EntityManagerInterface::class);
assert($entityManager instanceof EntityManagerInterface);
$sqlClient = $entityManager->getRepository(SqlClient::class)->findOneBy(['name' => $_ENV['TEST_SERVER_NAME']]);
assert($sqlClient instanceof SqlClient);
$remote = new DatabaseSchemaRepository($sqlClient);
$dbName = (string) $_ENV['TEST_DB_NAME_ONE'];

if ('up' === $action) {
    $remote->dropDatabase($dbName);
    $remote->createDatabase($dbName);
    $remote->useDbName($dbName);
    $remote->createDummyTable();
    $remote->populateDummiTable();
} else {
    $remote->dropDatabase($dbName);
}

$kernel->shutdown();
