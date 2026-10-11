<?php

declare(strict_types=1);

namespace Bulbula\Backend\Integrations\Google;

use Bulbula\Backend\Domain\Identity\Email;
use DateTimeImmutable;

/**
 * A Google assertion that has passed every check (GA-1…GA-4).
 *
 * Nothing here is attacker-controlled any more: the object only exists because
 * the signature, issuer, audience and expiry were all accepted. `email` is
 * present only when Google asserted it as verified (GA-3).
 */
final readonly class GoogleIdToken
{
    public function __construct(
        public string $subject,
        public Email $email,
        public string $audience,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
        public ?string $name = null,
        public ?string $picture = null,
    ) {
    }
}
