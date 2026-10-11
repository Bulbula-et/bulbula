<?php

declare(strict_types=1);

namespace Bulbula\Backend\Security;

/**
 * The answer to one rate-limit question.
 *
 * The wait travels with the verdict because they are produced together: the
 * count and the window that produced it are the same row, and a caller that
 * had to ask twice could get two different answers.
 */
final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $retryAfter,
    ) {
    }
}
