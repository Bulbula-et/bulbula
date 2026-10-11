<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Platform;

/**
 * Who acted, at the coarsest level that is always knowable.
 *
 * Guest, Customer, Operator and Administrator are the actors the operations
 * model defines (OM §3). A Business owner is deliberately **not** here: no
 * Business principal exists (D-54, TR-40), so there is nobody for such an
 * entry to describe.
 *
 * **`System` is an owner decision (2026-10-11), not an invention.** Actions
 * the platform takes by itself — a scheduled job, a retention sweep — have to
 * be recorded, and the only truthful actor for them is the platform. The
 * alternatives were both worse: naming the Administrator who scheduled a job
 * blames a person for something a machine did, and leaving no entry means a
 * change to live data with no record at all. OM §3's prose ("exactly three
 * platform actors") and its own table already disagreed with each other, so
 * this case is recorded beside that contradiction rather than hidden in code.
 *
 * `System` is **not** an account: it is never authenticated and never carries
 * an actor id, which is what `isAuthenticated()` says below.
 */
enum ActorType: string
{
    case Guest = 'guest';
    case Customer = 'customer';
    case Operator = 'operator';
    case Administrator = 'administrator';
    case System = 'system';

    /**
     * True for actors that are authenticated, and therefore must carry an id.
     *
     * A guest and the platform itself have no account to name; everybody else
     * must be identified, because an audit entry that cannot say who acted is
     * not an audit trail.
     */
    public function isAuthenticated(): bool
    {
        return match ($this) {
            self::Guest, self::System => false,
            self::Customer, self::Operator, self::Administrator => true,
        };
    }
}
