<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Directory;

use Bulbula\Backend\Domain\Directory\Area;
use Bulbula\Backend\Domain\Directory\Branch;
use Bulbula\Backend\Domain\Directory\Business;
use Bulbula\Backend\Domain\Directory\Category;
use Bulbula\Backend\Domain\Directory\SubCity;

/**
 * Everything a public page or a public API response shows about one Business,
 * read in one place and in a fixed number of queries (TD-05).
 *
 * It is a reading of the record, not a copy of it: the Business, its
 * Branches and the reference rows they point at, already ordered the way the
 * surfaces render them — the primary Category first (IAR-21), the primary
 * Branch first (D-03). A surface never queries; it renders this.
 */
final readonly class ListingProfile
{
    /**
     * @param list<Category>       $categories every Category, the primary one first (D-57)
     * @param array<int, Category> $parents    parent Categories by id — only for the Subcategories present
     * @param list<Branch>         $branches   every Branch, the primary one first (D-03)
     * @param array<int, Area>     $areas      the Areas of those Branches, keyed by id
     * @param array<int, SubCity>  $subCities  the Sub-cities of those Branches, keyed by id
     */
    public function __construct(
        public Business $business,
        public ?Category $primaryCategory,
        public array $categories,
        public array $parents,
        public array $branches,
        public array $areas,
        public array $subCities,
    ) {
    }

    /** The parent of a Subcategory, when the caller needs the full path (IAR-21). */
    public function parentOf(Category $category): ?Category
    {
        return $category->parentId !== null ? ($this->parents[$category->parentId] ?? null) : null;
    }

    public function areaOf(?Branch $branch): ?Area
    {
        return $branch !== null ? ($this->areas[$branch->areaId] ?? null) : null;
    }

    public function subCityOf(?Branch $branch): ?SubCity
    {
        return $branch !== null ? ($this->subCities[$branch->subCityId] ?? null) : null;
    }
}
