<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Identity;

/**
 * A session and the one copy of its credentials.
 *
 * Both values are returned exactly once, at issue, and are never readable
 * again — the record keeps only their hashes. Whoever holds this object is
 * responsible for putting them where they belong (hardened cookies for the
 * web, the `Authorization` header for an API client) and for not logging them
 * (T-2).
 *
 * The CSRF token is the second credential, not a convenience: every
 * cookie-authenticated state-changing request has to carry it (CS-1).
 */
final readonly class IssuedSession
{
    public function __construct(
        public Session $session,
        public string $token,
        public string $csrfToken,
    ) {
    }
}
