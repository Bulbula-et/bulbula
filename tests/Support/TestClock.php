<?php

declare(strict_types=1);

namespace Bulbula\Tests\Support;

use Bulbula\Backend\Support\Clock;
use DateTimeImmutable;

/**
 * A clock the test moves by hand.
 *
 * Time-dependent behaviour — a code expiring, a cooldown elapsing, a session
 * going idle — is tested by moving the clock, never by sleeping. A test that
 * sleeps is slow and still racy; a test that advances the clock is neither.
 */
final class TestClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds');
    }
}
