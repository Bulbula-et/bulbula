<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Directory;

use DateTimeImmutable;

/**
 * A physical location of a Business (data-model §3.2).
 *
 * Every Business has at least one Branch; a single-location business has
 * exactly one and is never a special case (D-03, PRD BM-1/BM-2). Area and
 * Sub-city are required (GEO-6) — administrative correctness is not a
 * navigation decision, even though V1 exposes no Sub-city URL.
 */
final readonly class Branch
{
    public function __construct(
        public int $id,
        public int $businessId,
        public int $areaId,
        public int $subCityId,
        public ?string $label,
        public ?string $addressLine,
        public ?float $latitude,
        public ?float $longitude,
        public ?string $phone,
        public ?string $phoneSecondary,
        public ?string $email,
        public BranchState $state,
        public bool $isPrimary,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function isPermanentlyClosed(): bool
    {
        return $this->state->isPermanentlyClosed();
    }
}
