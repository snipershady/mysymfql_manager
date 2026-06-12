<?php

use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/.env')) {
    (new Dotenv())->loadEnv(__DIR__ . '/.env');
}
