<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Search;

/**
 * The source rows one search document is composed from — the builder's input.
 *
 * Aliases arrive already attached to their targets: they are matching inputs
 * (AL-3) and never display labels, so the builder folds them into the
 * composed text and nothing else.
 */
final readonly class DocumentSources
{
    /**
     * @param list<array{categoryId: int, isPrimary: bool, name: string, nameAm: ?string, aliases: list<array{text: string, language: ?string}>}> $categories
     * @param list<array{name: string, nameAm: ?string, aliases: list<array{text: string, language: ?string}>}>                               $parentCategories text-only: a Subcategory listing is also a listing of its parent ("24 hour" is a Pharmacy), so the path's names and Aliases belong in the matching text — the facet rows stay exact (D-06, §3.2)
     * @param list<array{id: int, name: string, nameAm: ?string, subCityId: int, aliases: list<array{text: string, language: ?string}>}>       $areas
     * @param list<array{name: string, nameAm: ?string}>                                                                                     $subCities
     * @param list<string>                                                                                                                   $branchLabels
     */
    public function __construct(
        public string $name,
        public ?string $nameAm,
        public ?string $shortDescription,
        public ?string $longDescription,
        public string $status,
        public ?int $primaryCategoryId,
        public ?int $areaId,
        public ?int $subCityId,
        public ?float $latitude,
        public ?float $longitude,
        public string $createdAt,
        public string $updatedAt,
        public array $categories,
        public array $parentCategories,
        public array $areas,
        public array $subCities,
        public array $branchLabels,
    ) {
    }
}
