<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Identity;

/** The states a Customer record can be in (data-model §5.1, C-36). */
enum CustomerState: string
{
    case Active = 'active';
    case Deleted = 'deleted';
}
