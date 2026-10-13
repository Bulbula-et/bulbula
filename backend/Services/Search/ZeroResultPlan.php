<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Search;

/**
 * The designed zero-result destination (user-flows §A7).
 *
 * Every element of the flow is present and honest: a dropped filter is
 * **named**, never silently removed (UFL-A7.3); the results that come from
 * dropping it are labelled; the alternatives point at wider or neighbouring
 * destinations rather than unrelated businesses (ZR-3); and the correction
 * route needs no account (UFL-A7.6). Nothing here may be filled with a
 * Sponsored placement (UFL-A7.4) — there is no advertising code in this
 * pipeline at all (SP-1, SP-8).
 */
final readonly class ZeroResultPlan
{
    /**
     * @param list<array{label: string, url: string}> $alternatives
     */
    public function __construct(
        public ?string $droppedFilter,
        public ?string $droppedLabel,
        public ?SearchResults $droppedResults,
        public array $alternatives,
    ) {
    }
}
