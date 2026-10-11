<?php

declare(strict_types=1);

namespace Bulbula\Backend\Mail;

/**
 * Something that can deliver a message.
 *
 * An interface because there are already two implementations with different
 * reaches — a local file for development, a log for non-secret mail — and
 * because the provider selected under D-41 will not be known to any caller.
 * Implementations that speak to a network belong in `Backend\Integrations`,
 * which is the only namespace allowed to own an outbound transport (TD-07).
 */
interface Mailer
{
    /**
     * @throws \RuntimeException when the message could not be delivered
     */
    public function send(Message $message): void;
}
