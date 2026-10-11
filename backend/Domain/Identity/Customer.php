<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Identity;

use DateTimeImmutable;

/**
 * An authenticated end user (data-model §5.1).
 *
 * The fields are the model's minimal set and no more: an address, a display
 * name, a state and the times. There is no password field, and there never
 * will be (AI-2) — the absence is the design, not an omission.
 */
final readonly class Customer
{
    public function __construct(
        public int $id,
        public string $publicRef,
        public Email $email,
        public ?string $displayName,
        public CustomerState $state,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function isDeleted(): bool
    {
        return $this->state === CustomerState::Deleted;
    }
}
