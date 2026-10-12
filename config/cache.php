<?php

declare(strict_types=1);

use Bulbula\Backend\Core\Env;

/**
 * How long a public response may be reused, and how long it may keep being
 * served while a fresh one is fetched in the background (api-spec §1.8,
 * `performance-and-caching.md` §6).
 *
 * Seconds, because the header speaks seconds. Values, not constants, so a busy
 * day can shorten them without a deploy (TR-196); the defaults are the shipped
 * policy and are short enough that a correction to a Listing is visible within
 * minutes.
 */
return [
    'public_read' => [
        'max_age'                => Env::int('CACHE_PUBLIC_MAX_AGE', 300),
        'stale_while_revalidate' => Env::int('CACHE_PUBLIC_STALE_WHILE_REVALIDATE', 3600),
    ],
];
