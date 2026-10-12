<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Operations;

/** How permission was given (data-model §4.1): in person, by telephone, in writing (D-50). */
enum PermissionMethod: string
{
    case InPerson = 'in_person';
    case Telephone = 'telephone';
    case Written = 'written';
}
