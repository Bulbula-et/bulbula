<?php

declare(strict_types=1);

/**
 * Development router for PHP's built-in server.
 *
 *   composer serve            → http://localhost:8000
 *
 * Apache serves public/ directly in production; the built-in server has no
 * rewrite rules, so this file does the two things .htaccess does: hand an
 * existing file back to the server, and send everything else to the front
 * controller. It is never used in production, and it lives outside public/ so
 * it cannot be reached by URL.
 */

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = __DIR__ . '/../public' . (is_string($path) ? $path : '/');

// A real file (asset, robots.txt) is served by the server itself.
if (is_file($file)) {
    return false;
}

require __DIR__ . '/../public/index.php';
