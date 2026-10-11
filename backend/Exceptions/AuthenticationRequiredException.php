<?php

declare(strict_types=1);

namespace Bulbula\Backend\Exceptions;

/**
 * 401 — the caller is not signed in, or the token they presented is not one.
 *
 * One exception for both, and one message: distinguishing "no token" from
 * "wrong token" tells an attacker whether a stolen value was once real (E-5).
 */
final class AuthenticationRequiredException extends HttpException
{
    public function __construct(string $message = 'A valid session is required.')
    {
        parent::__construct(401, $message);
    }
}
