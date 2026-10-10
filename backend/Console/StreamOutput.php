<?php

declare(strict_types=1);

namespace Bulbula\Backend\Console;

/**
 * Writes to two streams — STDOUT and STDERR by default.
 *
 * Both are constructor arguments so a test can pass in-memory streams; a
 * command that hard-coded `STDOUT` would be untestable by construction.
 */
final class StreamOutput implements Output
{
    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private $stdout,
        private $stderr,
    ) {
    }

    public static function console(): self
    {
        return new self(STDOUT, STDERR);
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stdout, $text . PHP_EOL);
    }

    public function error(string $text): void
    {
        fwrite($this->stderr, $text . PHP_EOL);
    }
}
