<?php

declare(strict_types=1);

use Bulbula\Backend\Core\Router;

/**
 * API surface: JSON for other systems (and for the Telegram Mini App).
 *
 * Every path lives under /api/v1/ and returns JSON in every case, including
 * failures. Version is in the path, not in a header, so a client's target is
 * always unambiguous.
 */
return static function (Router $router): void {
    // Authentication (api-spec §3.1). Bearer tokens, no cookies and therefore
    // no CSRF (CS-3): a client sends the token back in the Authorization
    // header, which no page can set on its behalf.
    $auth = 'Bulbula\Api\Controllers\V1\AuthController';

    $router->post('/api/v1/auth/otp/request', [$auth, 'requestCode']);
    $router->post('/api/v1/auth/otp/verify', [$auth, 'verify']);
    $router->post('/api/v1/auth/logout', [$auth, 'logout']);

    // The operational probes — /api/v1/health and /api/v1/health/ready — are
    // declared in routes/health.php as their own family: no session, no rate
    // limit, never cached. They register here if this file ever needs to know
    // about them, which it should not.
};
