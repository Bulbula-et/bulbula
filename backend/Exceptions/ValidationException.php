<?php

declare(strict_types=1);

namespace Bulbula\Backend\Exceptions;

/**
 * 422 with field-level detail — the one place where an error response may say
 * more than "no", because the caller can act on it.
 */
final class ValidationException extends HttpException
{
    /** @param array<string, string> $errors field => message */
    public function __construct(public readonly array $errors = [], string $message = '')
    {
        parent::__construct(422, $message);
    }
}
