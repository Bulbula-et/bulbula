<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Search;

/**
 * The structured filter state of one query (search-design §8.1).
 *
 * Every filter is resolved to ids before the port sees it, so the matching
 * stages never learn what a slug means and a relaxed match cannot escape an
 * explicit filter (MT-1): the same structure is applied at every stage.
 *
 * `categoryIds` is already expanded — the Category and its Subcategories
 * (D-06 is exactly two levels), because a 24-hour pharmacy *is* a pharmacy.
 * `subCityId` is the broader fallback for `areaId` (§8.1): when the area
 * filter is what zeroes a result set, the same Category in the surrounding
 * Sub-city is the honest wider answer.
 */
final readonly class SearchFilters
{
    /**
     * @param list<int> $categoryIds
     */
    public function __construct(
        public array $categoryIds = [],
        public ?int $areaId = null,
        public ?int $subCityId = null,
        public bool $openNow = false,
        public ?float $minRating = null,
        public bool $verifiedOnly = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->categoryIds === []
            && $this->areaId === null
            && !$this->openNow
            && $this->minRating === null
            && !$this->verifiedOnly;
    }

    /**
     * The machine names of the active filters, in the drop order of §11.
     *
     * @return list<string>
     */
    public function activeKeys(): array
    {
        $keys = [];

        if ($this->openNow) {
            $keys[] = 'open_now';
        }

        if ($this->minRating !== null) {
            $keys[] = 'min_rating';
        }

        if ($this->verifiedOnly) {
            $keys[] = 'verified';
        }

        if ($this->areaId !== null) {
            $keys[] = 'area';
        }

        if ($this->categoryIds !== []) {
            $keys[] = 'category';
        }

        return $keys;
    }
}
