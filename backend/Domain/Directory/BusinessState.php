<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Directory;

/**
 * The Business lifecycle (data-model §3.1): `draft → in_review → published →
 * (unpublished | closed)`.
 *
 * **Closure is a state, never a delete** (TR-53): the row, its URL and its
 * history survive a permanent closure, which is why `Closed` exists here next
 * to `Unpublished` rather than being expressed as absence.
 */
enum BusinessState: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Closed = 'closed';

    public function isPublished(): bool
    {
        return $this === self::Published;
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }
}
