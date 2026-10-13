<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Search;

use Bulbula\Backend\Domain\Directory\Area;
use Bulbula\Backend\Domain\Directory\Category;

/**
 * One search or browse request, already parsed and resolved.
 *
 * The surface owns the HTTP question ("what did the address say?"); this
 * owns none of it. `category` and `area` arrive resolved to catalogue rows —
 * an unknown slug never reaches here, because the surface answers not-found
 * for a destination that does not exist and treats an unknown *filter* as
 * simply unmatchable.
 */
final readonly class SearchRequest
{
    public function __construct(
        public string $query = '',
        public ?Category $category = null,
        public ?Area $area = null,
        public bool $openNow = false,
        public ?float $minRating = null,
        public bool $verifiedOnly = false,
        public string $sort = 'relevance',
        public int $page = 1,
    ) {
    }
}
