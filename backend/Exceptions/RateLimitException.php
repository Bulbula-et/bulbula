<?php

declare(strict_types=1);

namespace Bulbula\Backend\Exceptions;

/**
 * Too many requests of one kind, from one source (TRD §34, OTP-6).
 *
 * **429 carries `Retry-After`** — `api-spec-v1.0.md` §1.5 lists it and E-6
 * forbids disclosing the *limit*, which is a different thing from how long the
 * current window has left to run. A polite client backs off by the header; a
 * rude one is refused either way.
 *
 * The message says nothing about which limit was hit: telling a caller whether
 * the address or the network was the reason hands them a way to probe both.
 */
final class RateLimitException extends HttpException
{
    public function __construct(int $retryAfter, string $message = 'Too many requests. Try again shortly.')
    {
        parent::__construct(429, $message, ['Retry-After' => (string) max(1, $retryAfter)]);
    }
}
