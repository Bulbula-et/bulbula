<?php

declare(strict_types=1);

namespace Bulbula\Backend\Exceptions;

/**
 * 404. Also the correct answer where the existence of a record is itself
 * privileged: an unauthenticated caller must not be told that something exists
 * but is forbidden — "not found" and "not allowed" are indistinguishable.
 */
final class HttpNotFoundException extends HttpException
{
    public function __construct(string $message = '')
    {
        parent::__construct(404, $message);
    }
}
