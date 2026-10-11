<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Identity;

/**
 * Which kind of principal a session belongs to (data-model §5.4).
 *
 * Customers and Staff are separate principals with separate records and
 * separate sessions; there is no path from one to the other (TR-39, PRD ACC-8).
 * Naming both here is what lets one session record serve the console one day
 * without pretending the two are the same thing.
 */
enum PrincipalType: string
{
    case Customer = 'customer';
    case Staff = 'staff';
}
