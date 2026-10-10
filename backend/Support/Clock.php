<?php

declare(strict_types=1);

namespace Bulbula\Backend\Support;

use DateTimeImmutable;

/**
 * The only source of "now" (TR-05).
 *
 * Time is injected rather than read, so a test can put a business rule at a
 * boundary — an edit window closing, a campaign starting, a session expiring —
 * without sleeping and without reaching into a class to override a method.
 * Code that calls `date()`, `time()` or `new DateTimeImmutable()` directly is
 * untestable at exactly the moments that matter.
 */
interface Clock
{
    /** The current instant, always in UTC. */
    public function now(): DateTimeImmutable;
}
