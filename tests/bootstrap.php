<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

/*
 * Pin the environment before Dotenv runs.
 *
 * PHPUnit's <server> entries only reach $_SERVER, while Symfony's KernelTestCase
 * resolves the environment from $_ENV first. Leaving that to chance means the
 * kernel boots in dev — which silently disables framework.test and produces the
 * baffling "you cannot create the client used in functional tests" error.
 */
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
putenv('APP_ENV=test');

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
