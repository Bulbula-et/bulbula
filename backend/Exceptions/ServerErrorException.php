<?php

declare(strict_types=1);

namespace Bulbula\Backend\Exceptions;

/**
 * 500 — something is wrong with the application rather than with the request.
 *
 * Used for conditions that can only be a programming error: a route pointing at
 * a handler that does not exist, or one that returns something that is not a
 * Response. The public response says nothing about which.
 */
final class ServerErrorException extends HttpException
{
    public function __construct(string $message = '')
    {
        parent::__construct(500, $message);
    }
}
