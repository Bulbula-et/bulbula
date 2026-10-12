<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Directory;

/**
 * A Branch's own status (data-model §3.2) — deliberately **distinct from
 * Business closure**: one branch of a chain can close while the business
 * continues, and a temporarily closed branch is still a place people are
 * looking for.
 */
enum BranchState: string
{
    case Active = 'active';
    case TemporarilyClosed = 'temporarily_closed';
    case PermanentlyClosed = 'permanently_closed';

    public function isPermanentlyClosed(): bool
    {
        return $this === self::PermanentlyClosed;
    }
}
