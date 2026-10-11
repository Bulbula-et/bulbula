<?php

declare(strict_types=1);

namespace Bulbula\Backend\Console;

/**
 * Where a command writes.
 *
 * Injected rather than echoed, so a test can assert what a command said
 * without capturing the process's stdout — and so a command cannot quietly
 * write to a stream nobody is reading.
 */
interface Output
{
    public function line(string $text = ''): void;

    /** For failures: a different stream, so a script can separate them. */
    public function error(string $text): void;
}
