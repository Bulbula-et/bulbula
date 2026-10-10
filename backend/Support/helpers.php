<?php

declare(strict_types=1);

use Bulbula\Backend\Core\View;

/**
 * Template helpers. Deliberately three, and deliberately global: templates
 * should read like markup, not like PHP.
 */

if (!function_exists('e')) {
    /** Escape a value for HTML output. Use it for every value you print. */
    function e(?string $value): string
    {
        return View::escape($value);
    }
}

if (!function_exists('asset')) {
    /** A public asset path, cache-busted by file modification time. */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $base = defined('BASE_PATH') ? constant('BASE_PATH') : dirname(__DIR__, 2);
        $file = (is_string($base) ? $base : dirname(__DIR__, 2)) . '/public/assets/' . $path;

        return is_file($file)
            ? '/assets/' . $path . '?v=' . substr((string) filemtime($file), -6)
            : '/assets/' . $path;
    }
}

if (!function_exists('url')) {
    /** An application path. Absolute URLs are built from APP_URL when needed. */
    function url(string $path = '/'): string
    {
        return '/' . ltrim($path, '/');
    }
}
