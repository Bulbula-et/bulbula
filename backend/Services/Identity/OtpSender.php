<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Identity;

use Bulbula\Backend\Domain\Identity\Email;

/**
 * How a sign-in code reaches the person who asked for it.
 *
 * An interface rather than a mail client, because the identity slice must not
 * own a transport: the adapter that speaks to the mail provider belongs in
 * `Backend\Integrations` (TD-07), and this is the seam it plugs into.
 *
 * The contract is deliberately narrow — address in, code out — so an
 * implementation cannot decide to log it (OTP-10) or hand it to a queue that
 * might.
 */
interface OtpSender
{
    /**
     * @throws \RuntimeException when the code could not be handed to a transport
     */
    public function send(Email $email, string $code): void;
}
