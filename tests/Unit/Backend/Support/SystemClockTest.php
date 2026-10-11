<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Support;

use Bulbula\Backend\Support\Clock;
use Bulbula\Backend\Support\SystemClock;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One property matters and is easy to lose: UTC. An instant that depends on the
 * server's timezone is a bug that only appears on somebody else's deployment.
 */
final class SystemClockTest extends TestCase
{
    #[Test]
    public function it_is_a_clock(): void
    {
        self::assertInstanceOf(Clock::class, new SystemClock());
    }

    #[Test]
    public function it_answers_in_utc(): void
    {
        $now = (new SystemClock())->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
    }

    #[Test]
    public function it_answers_in_utc_even_when_the_process_timezone_is_not(): void
    {
        // TR-05: business rules read the clock, never the process's idea of
        // local time. A deployment with a different `date.timezone` must not
        // change what is stored.
        $original = date_default_timezone_get();
        date_default_timezone_set('Africa/Addis_Ababa');

        try {
            self::assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
        } finally {
            date_default_timezone_set($original);
        }
    }

    #[Test]
    public function it_returns_an_immutable_instant(): void
    {
        // Nothing may reach back into a recorded time and change it: shifting
        // the moment or reading it in another timezone both yield a new object
        // and leave the original alone.
        $now = (new SystemClock())->now();
        $timestamp = $now->getTimestamp();

        self::assertNotSame($now, $now->modify('+1 hour'));
        self::assertSame($timestamp, $now->getTimestamp());

        $elsewhere = $now->setTimezone(new DateTimeZone('Africa/Addis_Ababa'));

        self::assertNotSame($now, $elsewhere);
        self::assertSame($timestamp, $elsewhere->getTimestamp());
        self::assertSame($timestamp, $now->getTimestamp());
    }
}
