<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Directory;

/**
 * The user-facing location unit (data-model §3.6). Every Area belongs to a
 * Sub-city, which is where its administrative parentness lives for V1 (GEO-5).
 */
final readonly class Area
{
    public function __construct(
        public int $id,
        public int $subCityId,
        public Slug $slug,
        public string $name,
        public ?string $nameAm,
        public int $displayOrder,
        public bool $visible,
    ) {
    }
}
