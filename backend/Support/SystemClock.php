<?php

declare(strict_types=1);

namespace Bulbula\Backend\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The system clock: UTC, to the second.
 *
 * UTC because every stored instant is UTC (the display timezone is a
 * presentation concern, handled where a date is rendered), and because an
 * instant that depends on the server's `date.timezone` is a bug that only
 * appears on somebody else's deployment.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
