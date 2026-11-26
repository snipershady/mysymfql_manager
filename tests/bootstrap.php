<?php

declare(strict_types=1);

use App\Kernel;
use App\Tests\Fixtures\TestCatalog;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Fresh, known application catalog for every run (dedicated *_test database, see TestCatalog).
// Skipped for the unit suite, which must run without MySQL: `phpunit --testsuite unit`
// (or TEST_CATALOG=skip for any other selection that does not touch the database).
$argv = $_SERVER['argv'] ?? [];
$onlyUnitSuite = (false !== ($i = array_search('--testsuite', $argv, true)) && 'unit' === ($argv[$i + 1] ?? null))
    || in_array('--testsuite=unit', $argv, true);

if (!$onlyUnitSuite && 'skip' !== ($_SERVER['TEST_CATALOG'] ?? null)) {
    // Outside dama/doctrine-test-bundle's per-test transaction: migrations run DDL (implicit commits in MySQL).
    $keepStatic = StaticDriver::isKeepStaticConnections();
    StaticDriver::setKeepStaticConnections(false);
    try {
        $kernel = new Kernel('test', (bool) $_SERVER['APP_DEBUG']);
        $kernel->boot();
        TestCatalog::rebuild($kernel);
        $kernel->shutdown();
    } finally {
        StaticDriver::setKeepStaticConnections($keepStatic);
    }
}
