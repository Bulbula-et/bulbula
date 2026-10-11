<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Identity;

use Bulbula\Backend\Domain\Identity\Email;
use Bulbula\Backend\Exceptions\ConfigurationException;

/**
 * What is wired until the mail integration exists — and it fails.
 *
 * The tempting alternative is a sender that writes the code to the log so
 * sign-in "works" in development. That is exactly what OTP-10 forbids: a code
 * in a log file is a credential in a place nobody rotates, visible to everyone
 * who can read logs, and it would look like a working login while being a
 * disclosure. A loud failure is the honest state until
 * `Backend\Integrations` speaks to the mail provider (D-41).
 */
final class UndeliverableOtpSender implements OtpSender
{
    public function send(Email $email, string $code): void
    {
        throw new ConfigurationException(
            'No mail transport is configured, so a sign-in code cannot be delivered. '
            . 'The outbound mail adapter (D-41) is not built yet; until it is, this service '
            . 'refuses rather than pretending to send, or logging a code (OTP-10).',
        );
    }
}
