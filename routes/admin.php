<?php

declare(strict_types=1);

use Bulbula\Backend\Core\Router;

/**
 * Admin surface: the moderation and operations console.
 *
 * Separate from the web surface in both URL space and code. Every route here
 * requires an authenticated session with a named permission; the check belongs
 * to the controller (it can then be tested), never to the route file.
 *
 * **Nothing is registered yet, and that is deliberate.** The console is step 6
 * of the build order (`docs/70-engineering/project-structure-v2.md` §9), and
 * `architecture.md` §6 is explicit: a route is registered *with the slice that
 * implements it*, because a registered path with no implementation behind it is
 * a promise the application cannot keep — the visitor sees an error page, and
 * the failure reads like an outage rather than like a missing feature.
 *
 * That is not hypothetical. This file used to register `/admin` for a
 * controller that had never been written: the URL answered `500` on the live
 * host, and the owner found it by opening it. The planned paths stay here, as
 * they do in `routes/web.php`, until the slice that implements them lands:
 *
 *   step 6  /ops                  the dashboard
 *           /ops/{section}        the queues: listings, reviews, reports
 *
 * The web prefix for this surface is `/ops/*`, not `/admin`
 * (`architecture.md` §6.1 — the family table fixes one prefix per surface, and
 * the operations JSON API repeats it under `/api/v1/ops/*`).
 */
return static function (Router $router): void {
    // Routes arrive with the console slice. Until then this file registers
    // nothing, so `/ops` and `/admin` are honestly absent (404) rather than
    // present and broken.
};
