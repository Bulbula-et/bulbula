<?php

declare(strict_types=1);

use Bulbula\Backend\Core\Router;

/**
 * Operational probes — their own route family.
 *
 * They are JSON, they never require a session, they are never rate limited
 * (a monitor that is throttled reports an outage that is not happening) and
 * they must not be cached. That is why they are declared here rather than
 * folded into the public or API route files: a family exists so those four
 * properties are set in one place instead of per route.
 *
 * The bare `/health` paths are the ones an uptime monitor and the deployment
 * smoke test use; the `/api/v1` twins exist so a client that already speaks
 * the API can ask without a second host rule.
 */
return static function (Router $router): void {
    $controller = 'Bulbula\Api\Controllers\V1\HealthController';

    $router->get('/health', [$controller, 'live']);
    $router->get('/health/ready', [$controller, 'ready']);
    $router->get('/api/v1/health', [$controller, 'live']);
    $router->get('/api/v1/health/ready', [$controller, 'ready']);
};
