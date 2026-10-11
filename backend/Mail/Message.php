<?php

declare(strict_types=1);

namespace Bulbula\Backend\Mail;

/**
 * One message, ready to hand to a transport.
 *
 * Plain text only: there is no HTML body in V1, because every message the
 * product sends is a short factual line — a sign-in code, later a
 * notification — and an HTML body would be a template system, a sanitising
 * problem and a tracking surface, none of which V1 has asked for.
 *
 * The recipient is a string rather than an `Email`, so this layer knows
 * nothing about identity: mail is a transport concern, and a transport that
 * imports the domain would eventually be a place a business rule hides.
 */
final readonly class Message
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
    ) {
    }
}
