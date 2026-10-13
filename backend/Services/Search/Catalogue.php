<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Search;

use Bulbula\Backend\Domain\Directory\Area;
use Bulbula\Backend\Domain\Directory\Category;
use Bulbula\Backend\Domain\Directory\SubCity;

/**
 * What the discovery indexes show: the catalogue and the geography, each with
 * its **real** count (public-web-ux §3, §5).
 *
 * Only destinations that have published Listings appear (C-04, IAR-14) — an
 * empty Category is not listed at all — and a parent's count rolls its
 * Subcategories up, because a 24-hour pharmacy *is* a pharmacy (D-06).
 */
final readonly class Catalogue
{
    /**
     * @param list<array{category: Category, count: int}>          $categories top-level, with rolled-up counts
     * @param list<array{subCity: SubCity, areas: list<array{area: Area, count: int}>}> $areas grouped by Sub-city (§5)
     */
    public function __construct(
        public array $categories,
        public array $areas,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->categories === [] && $this->areas === [];
    }
}
