<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Directory;

use DateTimeImmutable;

/**
 * A brand-level entity (data-model §3.1).
 *
 * The Business level owns the name, the descriptions, the website and the
 * public social links; address, area, coordinates, phones and hours belong to
 * a Branch (D-55, DM-R1) and are not movable here for convenience.
 *
 * `version` is the optimistic-concurrency token (TR-57): the entity carries
 * the version it was read at, and an update states it back so a concurrent
 * edit fails loudly instead of overwriting.
 */
final readonly class Business
{
    /**
     * @param array<string, string>|null $socialLinks platform => address, as the operator recorded them
     */
    public function __construct(
        public int $id,
        public Slug $slug,
        public string $name,
        public ?string $legalName,
        public ?string $nameAm,
        public ?string $shortDescription,
        public ?string $longDescription,
        public ?string $website,
        public ?string $email,
        public ?array $socialLinks,
        public BusinessState $state,
        public ?DateTimeImmutable $publishedAt,
        public ?DateTimeImmutable $unpublishedAt,
        public ?DateTimeImmutable $closedAt,
        public ?string $closureReason,
        public int $version,
        public ?int $createdBy,
        public DateTimeImmutable $createdAt,
        public ?int $updatedBy,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function isPublished(): bool
    {
        return $this->state->isPublished();
    }

    public function isClosed(): bool
    {
        return $this->state->isClosed();
    }
}
