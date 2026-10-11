<?php

declare(strict_types=1);

/**
 * Bulbula — single web entry point.
 *
 * This directory is the whole of the web root. Nothing above it is reachable
 * by URL: configuration, templates, storage and the application itself all sit
 * outside the document root on purpose.
 */

use Bulbula\Backend\Core\Application;

define('BASE_PATH', dirname(__DIR__));
define('APP_START', microtime(true));

if (PHP_VERSION_ID < 80400) {
    http_response_code(500);
    exit('Bulbula requires PHP 8.4 or newer.');
}

$autoload = BASE_PATH . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    exit('Dependencies are not installed. Run: composer install');
}

require $autoload;

(new Application(BASE_PATH))->run();
