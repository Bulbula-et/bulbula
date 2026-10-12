<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Operations;

/**
 * Granted or withdrawn (data-model §4.1, PRD TS-5). Withdrawal is recorded on
 * the same row and must be able to unpublish promptly; a refusal, separately,
 * creates no Business record for publication at all (RK-20).
 */
enum PermissionState: string
{
    case Granted = 'granted';
    case Withdrawn = 'withdrawn';

    public function isGranted(): bool
    {
        return $this === self::Granted;
    }
}
