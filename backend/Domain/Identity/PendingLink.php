<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Identity;

/**
 * A provider identity waiting to be attached to an existing Customer.
 *
 * It exists only between the provider's assertion and the person proving the
 * mailbox (LK-6, D-13 closed 2026-10-11). It is carried on the code that proves
 * it, which is why it is small: two strings, no state of its own.
 */
final readonly class PendingLink
{
    public function __construct(
        public string $provider,
        public string $subject,
    ) {
    }
}
