<?php

declare(strict_types=1);

namespace Bulbula\Backend\Exceptions;

/**
 * 401 — a provider's credential did not survive verification.
 *
 * One message for every reason (GA-2, E-5): an expired assertion, a foreign
 * audience, an unsigned token and a forged signature are told apart in the log
 * and nowhere else. The caller learns that it did not work, which is the only
 * thing they can act on.
 */
final class ProviderCredentialRejectedException extends HttpException
{
    public function __construct(string $message = 'We could not sign you in with that account.')
    {
        parent::__construct(401, $message);
    }
}
