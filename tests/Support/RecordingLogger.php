<?php

declare(strict_types=1);

namespace Bulbula\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Keeps every log record, so a test can assert what was *not* written.
 *
 * The interesting assertions in the identity tests are negative ones: no code,
 * no address, no token ever reaches a log line (TR-135, OTP-10). That claim is
 * only testable if the test holds the log.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** Everything the logger has seen, as one searchable string. */
    public function transcript(): string
    {
        $lines = [];

        foreach ($this->records as $record) {
            $lines[] = $record['message'] . ' ' . (string) json_encode($record['context']);
        }

        return implode("\n", $lines);
    }
}
