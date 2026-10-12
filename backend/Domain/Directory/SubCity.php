<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Directory;

/**
 * The administrative unit above an Area (data-model §3.6, GEO-6).
 *
 * The model's full hierarchy is Country → City/Region → Sub-city → Area
 * (GEO-1); V1 populates it one branch deep (GEO-5), so the city is a name on
 * this row rather than a table of its own.
 */
final readonly class SubCity
{
    public function __construct(
        public int $id,
        public Slug $slug,
        public string $name,
        public ?string $nameAm,
        public string $city,
    ) {
    }
}
