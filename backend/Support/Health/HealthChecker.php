<?php

declare(strict_types=1);

namespace Bulbula\Backend\Support\Health;

use Bulbula\Backend\Database\Connection;

/**
 * Liveness and readiness, asked honestly.
 *
 * Liveness answers only "is this process able to reply", and it must never
 * depend on the database — a database blip that restarts healthy processes
 * turns a small outage into a large one. Readiness answers "can this instance
 * actually serve requests", and that does depend on its dependencies.
 *
 * It lives outside the HTTP layers so the check can be run from a console
 * command or a job, and so a controller never has to know how a dependency is
 * probed.
 */
final class HealthChecker
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $storagePath,
    ) {
    }

    /** The process answered. Nothing else is asked. */
    public function liveness(): bool
    {
        return true;
    }

    public function readiness(): HealthReport
    {
        return new HealthReport([
            'database' => $this->connection->ping() ? 'ok' : 'error',
            'storage'  => $this->storageIsWritable() ? 'ok' : 'error',
        ]);
    }

    /** Logs, cache and uploads are what the application cannot work without. */
    private function storageIsWritable(): bool
    {
        return is_dir($this->storagePath) && is_writable($this->storagePath);
    }
}
