<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Search;

use DateTimeImmutable;

/**
 * One matched Business, as the retrieval layer knows it.
 *
 * The row carries exactly the inputs §9.1 of search-design.md admits for
 * ordering — and nothing else. There is no score field because there is no
 * approved weighting to compute one from (OR-1, D-09 open); the ranking is
 * the lexicographic key chain in configuration (OR-2), applied to these
 * columns.
 */
final readonly class MatchRow
{
    public function __construct(
        public int $businessId,
        public string $status,
        public bool $verified,
        public ?DateTimeImmutable $verifiedAt,
        public int $ratingCount,
        public ?float $ratingAverage,
        public ?int $completeness,
    ) {
    }
}
