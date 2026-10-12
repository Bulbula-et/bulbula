<?php

declare(strict_types=1);

namespace Bulbula\Backend\Exceptions;

/**
 * 409 — someone else edited the record first (TR-57).
 *
 * Concurrent edits are **detected, never silently merged or overwritten**: the
 * caller stated the version it read, the stored version has moved on, and the
 * only honest answer is to say so and let a person look at what changed. A
 * last-write-wins merge would destroy the other operator's work without ever
 * telling anyone.
 */
final class ConcurrencyConflictException extends HttpException
{
    public function __construct(
        public readonly int $expectedVersion = 0,
        string $message = 'This record changed since you read it. Reload it and apply your change again.',
    ) {
        parent::__construct(409, $message);
    }
}
