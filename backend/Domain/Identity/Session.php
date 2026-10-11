<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Identity;

use DateTimeImmutable;

/**
 * A server-side session record (data-model §5.4, TD-02).
 *
 * The token is not a field: only its hash is stored (S-3), so the client's
 * credential exists in the client's hands and in nobody else's.
 *
 * Two expiries travel together (S-6): `lastActiveAt + the inactivity window`
 * and `expiresAt`, the absolute cap. Both are enforced on every resolution —
 * an active session still has to end eventually.
 */
final readonly class Session
{
    public function __construct(
        public int $id,
        public PrincipalType $principalType,
        public int $principalId,
        public Surface $surface,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastActiveAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $revokedAt,
        public ?string $csrfHash = null,
    ) {
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function hasExpiredAt(DateTimeImmutable $now, int $inactivityWindow): bool
    {
        if ($this->expiresAt <= $now) {
            return true;
        }

        return $this->lastActiveAt->getTimestamp() + $inactivityWindow <= $now->getTimestamp();
    }
}
