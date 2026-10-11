<?php

declare(strict_types=1);

namespace Bulbula\Backend\Support\Health;

/**
 * The result of a readiness check: one named status per dependency.
 *
 * A flat map of check name to `ok` or `error` — deliberately not a boolean, so
 * a monitor and a human both learn *which* dependency is unhappy without
 * parsing a message.
 */
final readonly class HealthReport
{
    /** @param array<string, string> $checks */
    public function __construct(public array $checks)
    {
    }

    public function passes(): bool
    {
        return !in_array('error', $this->checks, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->passes() ? 'pass' : 'fail',
            'checks' => $this->checks,
        ];
    }
}
