<?php

declare(strict_types=1);

namespace Bulbula\Backend\Exceptions;

/**
 * A state-changing request whose CSRF token did not match (CS-1).
 *
 * 419 rather than 403: the request was not *forbidden*, it was not
 * authenticated as coming from the page it claimed to come from. The renderer
 * already maps 419 to `csrf_token_mismatch`, and the message tells a person to
 * reload the form rather than suggesting they did something wrong.
 */
final class CsrfTokenMismatchException extends HttpException
{
    public function __construct(string $message = 'The form expired. Please reload the page and try again.')
    {
        parent::__construct(419, $message);
    }
}
