<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Search;

/**
 * One Business's search document, fully composed (data-model §8.5,
 * search-design §3.2).
 *
 * Derived, never authoritative (SD-1): the entity tables are the source of
 * truth and `search:reindex` can rebuild this from them at any time (SD-6).
 * What is deliberately NULL or zero is as decided as what is filled —
 * `completeness` stays empty until D-09 defines it, verification and ratings
 * stay empty until their modules land, and `hasConfirmedHours` is false until
 * the hours model (D-04) exists (ON-4).
 */
final readonly class SearchDocumentData
{
    /**
     * @param list<array{token: string, inName: bool}>      $tokens
     * @param list<array{categoryId: int, isPrimary: bool}> $categories
     * @param list<int>                                     $areaIds
     */
    public function __construct(
        public string $status,
        public string $nameNorm,
        public string $nameText,
        public string $classificationText,
        public string $locationText,
        public string $descriptionText,
        public ?int $primaryCategoryId,
        public ?int $areaId,
        public ?int $subCityId,
        public bool $verified,
        public ?string $verifiedAt,
        public ?float $ratingAverage,
        public int $ratingCount,
        public ?int $completeness,
        public bool $hasConfirmedHours,
        public ?float $latitude,
        public ?float $longitude,
        public string $createdAt,
        public string $updatedAt,
        public array $tokens,
        public array $categories,
        public array $areaIds,
    ) {
    }
}
