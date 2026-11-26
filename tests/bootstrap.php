<?php

declare(strict_types=1);

use App\Kernel;
use App\Tests\Fixtures\TestCatalog;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Fresh, known application catalog for every run (dedicated *_test database, see TestCatalog).
$kernel = new Kernel('test', (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$testContainer = $kernel->getContainer()->get('test.service_container');
assert($testContainer instanceof Psr\Container\ContainerInterface);
TestCatalog::rebuild($testContainer);
$kernel->shutdown();
