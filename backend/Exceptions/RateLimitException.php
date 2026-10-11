<?php

declare(strict_types=1);

namespace Bulbula\Backend\Exceptions;

/**
 * Too many requests of one kind, from one source (TRD §34, OTP-6).
 *
 * 429, and deliberately no `Retry-After`: the window is configuration, and a
 * header that counts it down tells the caller exactly how the limit works
 * (config/security.php).
 */
final class RateLimitException extends HttpException
{
    public function __construct(string $message = 'Too many requests. Try again later.')
    {
        parent::__construct(429, $message);
    }
}
