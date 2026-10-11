<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Identity;

use DateTimeImmutable;

/**
 * One issued sign-in code, as stored (data-model §5.3, C-31).
 *
 * The code itself is **not** here and cannot be: only its hash is stored
 * (OTP-2), so a database disclosure does not hand over working codes. What the
 * object exposes is the state the verification rules need — issued, expires,
 * consumed, attempts so far, and the session it was bound to.
 */
final readonly class OtpChallenge
{
    public function __construct(
        public int $id,
        public Email $email,
        public string $codeHash,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $consumedAt,
        public int $attempts,
        public ?string $sessionBinding,
    ) {
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }

    public function hasExpiredAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function mayStillBeAttempted(int $ceiling): bool
    {
        return $this->attempts < $ceiling;
    }
}
