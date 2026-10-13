<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Search;

use Bulbula\Backend\Services\Directory\ListingProfile;

/**
 * One result card: the profile the card renders, plus the match-location
 * input of §9.1 — whether the query hit the Business's *name* — so a view can
 * say "name match" where it matters without re-deriving anything.
 */
final readonly class SearchResultItem
{
    public function __construct(
        public ListingProfile $profile,
        public bool $nameMatch,
    ) {
    }
}
