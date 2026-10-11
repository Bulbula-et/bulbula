<?php

declare(strict_types=1);

namespace Bulbula\Backend\Exceptions;

use RuntimeException;

/**
 * An HTTP failure that carries its own status.
 *
 * Throw one; the kernel renders it. Controllers never catch an exception to
 * build a response by hand, and the renderer decides what a caller is told.
 *
 * This class is abstract because only the specific cases are meaningful — a
 * bare 500 has nothing to say for itself.
 */
abstract class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    protected function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
