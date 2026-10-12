<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Operations;

use DateTimeImmutable;

/**
 * Evidence that a Business agreed to collection and publication (data-model §4.1).
 *
 * Personal data is minimised to the agreeing person's name and claimed role
 * (D-51). The publication gate reads the **most recent** record: a withdrawal
 * recorded after a grant stops publication, and a fresh grant restores it —
 * which is the only reading of "a Permission record is linked" (TR-49) that
 * lets a withdrawal actually take effect.
 */
final readonly class PermissionRecord
{
    public function __construct(
        public int $id,
        public int $businessId,
        public string $personName,
        public ?string $personRole,
        public PermissionMethod $method,
        public DateTimeImmutable $grantedAt,
        public ?int $obtainedBy,
        public ?string $scopeNote,
        public PermissionState $state,
        public ?DateTimeImmutable $withdrawnAt,
        public ?string $withdrawalReason,
        public ?string $evidenceReference,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function isGranted(): bool
    {
        return $this->state->isGranted();
    }
}
