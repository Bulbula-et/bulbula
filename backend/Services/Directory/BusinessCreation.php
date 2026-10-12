<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Directory;

use Bulbula\Backend\Domain\Directory\Business;

/**
 * What creating a Business produced: the record, and the duplicate candidates
 * that existed at that moment (TR-55).
 *
 * The candidates are **surfaced, not refused**: similar businesses genuinely
 * exist (data-model §3.1), so the decision belongs to the person who can see
 * both records. Returning them from the creation itself is what makes
 * "surfaced at creation time" true rather than aspirational.
 */
final readonly class BusinessCreation
{
    /**
     * @param list<Business> $duplicateCandidates
     */
    public function __construct(
        public Business $business,
        public array $duplicateCandidates,
    ) {
    }

    public function hasDuplicateCandidates(): bool
    {
        return $this->duplicateCandidates !== [];
    }
}
