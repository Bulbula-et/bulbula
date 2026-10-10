<?php

declare(strict_types=1);

namespace Bulbula\Backend\Exceptions;

/** 405, carrying the Allow header the HTTP specification requires. */
final class MethodNotAllowedException extends HttpException
{
    /** @param list<string> $allowed */
    public function __construct(array $allowed = [])
    {
        $headers = $allowed === [] ? [] : ['Allow' => implode(', ', $allowed)];

        parent::__construct(405, 'Method not allowed.', $headers);
    }
}
