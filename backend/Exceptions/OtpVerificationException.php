<?php

declare(strict_types=1);

namespace Bulbula\Backend\Exceptions;

/**
 * A sign-in code that could not be verified.
 *
 * One message for every reason: wrong code, expired code, consumed code, too
 * many attempts, wrong session. A response that distinguishes them tells an
 * attacker which half of the guess was right (OTP-8), so the reason is logged
 * server-side and never said out loud.
 */
final class OtpVerificationException extends HttpException
{
    public function __construct()
    {
        parent::__construct(422, 'The code could not be verified.');
    }
}
