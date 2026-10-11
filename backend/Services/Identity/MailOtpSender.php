<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Identity;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Domain\Identity\Email;
use Bulbula\Backend\Mail\Mailer;
use Bulbula\Backend\Mail\Message;

/**
 * Delivers a sign-in code by email.
 *
 * The message says what it is, how long it lasts, and what to do if it was not
 * requested — and nothing else. No marketing line, no tracking pixel, no link
 * containing a credential (T-1): the code is the only thing in the body a
 * person needs, and a link would put a credential in a place that gets
 * underlined, copied and pasted.
 */
final class MailOtpSender implements OtpSender
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly Config $config,
    ) {
    }

    public function send(Email $email, string $code): void
    {
        $minutes = (int) ceil($this->config->int('security.otp.ttl', 600) / 60);

        $this->mailer->send(new Message(
            to: $email->value,
            subject: 'Your Bulbula sign-in code',
            body: $code . "\n\n"
                . 'This code signs you in to Bulbula and expires in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.' . "\n"
                . 'If you did not ask to sign in, you can ignore this message — nobody can use it without this email.' . "\n",
        ));
    }
}
