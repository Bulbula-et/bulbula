<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Identity;

/**
 * A session and the one copy of its token.
 *
 * The token is returned exactly once, at issue, and is never readable again —
 * the record keeps only its hash. Whoever holds this object is responsible for
 * putting the token where it belongs (a hardened cookie for the web, the
 * `Authorization` header for an API client) and for not logging it (T-2).
 */
final readonly class IssuedSession
{
    public function __construct(
        public Session $session,
        public string $token,
    ) {
    }
}
