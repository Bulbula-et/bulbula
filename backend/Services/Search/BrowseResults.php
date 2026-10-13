<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Search;

use Bulbula\Backend\Domain\Directory\Area;
use Bulbula\Backend\Domain\Directory\Category;

/**
 * One page of a browse listing (Category, Subcategory, Category × Area,
 * Area) with the facet navigation the screens carry beside it.
 *
 * A filter-only request is a browse, not a search (MT-6): there are no match
 * stages here, only the filter set and the ordering keys.
 */
final readonly class BrowseResults
{
    /**
     * @param list<SearchResultItem>                 $items
     * @param list<array{category: Category, count: int}> $subcategories
     * @param list<array{area: Area, count: int}>         $areaFacets
     * @param list<array{category: Category, count: int}> $categoryFacets
     * @param list<array{category: Category, count: int}> $siblings
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $pageSize,
        public array $subcategories,
        public array $areaFacets,
        public array $categoryFacets,
        public array $siblings,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function hasPages(): bool
    {
        return $this->total > $this->pageSize;
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->pageSize)));
    }
}
