<?php

declare(strict_types=1);

namespace Bulbula\Backend\Exceptions;

/**
 * 409 — publication was refused, and the reasons are the requirements that are
 * not met yet (TR-49).
 *
 * The refusal is a rule, not a validation error: nothing the caller types into
 * a form fixes it, so it is not a 422; and it is not a 404, because the record
 * exists and the caller is entitled to know it. `reasons` carries the unmet
 * requirement keys so the operations surface can say *what* is missing.
 */
final class PublicationRefusedException extends HttpException
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly array $reasons = [],
        string $message = 'Publication is refused until every requirement is met.',
    ) {
        parent::__construct(409, $message);
    }
}
