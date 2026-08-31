<?php

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require_once dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$environment = $_SERVER['APP_ENV'] ?? 'dev';
$kernel = new Kernel(is_string($environment) ? $environment : 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));

return new Application($kernel);
