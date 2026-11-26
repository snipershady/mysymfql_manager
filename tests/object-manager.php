<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$environment = $_SERVER['APP_ENV'] ?? 'dev';
$kernel = new Kernel(is_string($environment) ? $environment : 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
