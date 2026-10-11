<?php

declare(strict_types=1);

use Bulbula\Backend\Core\Router;

/**
 * Admin surface: the moderation and operations console.
 *
 * Separate from the web surface in both URL space and code. Every route here
 * requires an authenticated session with a named permission; the check belongs
 * to the controller (it can then be tested), never to the route file.
 */
return static function (Router $router): void {
    $router->get('/admin', ['Bulbula\Admin\Controllers\DashboardController', 'index']);
};
