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
    // The operational probes — /api/v1/health and /api/v1/health/ready — are
    // declared in routes/health.php as their own family: no session, no rate
    // limit, never cached. They register here if this file ever needs to know
    // about them, which it should not.
};
