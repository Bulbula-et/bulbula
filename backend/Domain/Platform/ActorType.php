<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Platform;

/**
 * Who acted, at the coarsest level that is always knowable.
 *
 * These are the four actors the operations model defines (OM §3) — a Guest (no
 * account), a Customer, an Operator and an Administrator. A Business owner is
 * deliberately **not** here: no Business principal exists (D-54, TR-40), so
 * there is nobody for such an entry to describe.
 *
 * **Open question, not decided here:** how an action taken by the platform
 * itself — a scheduled job, a retention sweep — is attributed. That needs an
 * owner decision, and adding a fifth case now would invent it. Until then a job
 * that must audit something audits it as the actor on whose behalf it runs.
 */
enum ActorType: string
{
    case Guest = 'guest';
    case Customer = 'customer';
    case Operator = 'operator';
    case Administrator = 'administrator';

    /** True for actors that are authenticated, and therefore must carry an id. */
    public function isAuthenticated(): bool
    {
        return $this !== self::Guest;
    }
}
