<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Identity;

use Bulbula\Backend\Domain\Identity\Email;
use Bulbula\Backend\Domain\Identity\IssuedSession;
use LogicException;

/**
 * What a Google sign-in produced: a session, or a question.
 *
 * The second case is D-13 in code. Google asserted an address that already has
 * a Customer; the identity is not attached until the person proves the mailbox
 * (LK-6), so the service answers with the address a code was sent to instead of
 * a session.
 */
final readonly class GoogleSignInResult
{
    private function __construct(
        public ?IssuedSession $session,
        public ?Email $confirmationEmail,
    ) {
    }

    public static function signedIn(IssuedSession $session): self
    {
        return new self($session, null);
    }

    public static function confirmationRequired(Email $email): self
    {
        return new self(null, $email);
    }

    public function needsConfirmation(): bool
    {
        return $this->session === null;
    }

    /** @throws LogicException when no confirmation is pending */
    public function confirmationAddress(): Email
    {
        return $this->confirmationEmail ?? throw new LogicException('No confirmation is pending.');
    }

    /** @throws LogicException when the sign-in is waiting on a confirmation */
    public function issuedSession(): IssuedSession
    {
        return $this->session ?? throw new LogicException('The sign-in is waiting on a confirmation.');
    }
}
