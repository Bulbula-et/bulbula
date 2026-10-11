<?php

declare(strict_types=1);

namespace Bulbula\Backend\Security;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Domain\Identity\Session;

/**
 * CSRF tokens. Two shapes, because there are two situations (CS-1 … CS-4).
 *
 * **Before sign-in** there is no session to bind to, so the token is a
 * double-submit pair: a random value in a cookie that only this site can set,
 * echoed in the form and compared in constant time. It is stored as a *hash*
 * when it is used as an OTP context binding, so a database row never holds a
 * token that could be replayed.
 *
 * **After sign-in** the token is bound to the session: the raw value lives in
 * the browser, the hash lives on the session row, and it is replaced whenever
 * the session is (K-6, CS-4). A pre-authentication value is never promoted
 * into an authenticated one.
 */
final class Csrf
{
    public const FORM_FIELD = 'csrf';

    public function __construct(private readonly Config $config)
    {
    }

    /** The cookie the double-submit token travels in; a name, never a value. */
    public function cookieName(): string
    {
        return $this->config->string('security.csrf.cookie', 'bulbula_csrf');
    }

    /** 256 bits from the CSPRNG — the same standard as a session token. */
    public function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Constant-time, and false for anything empty: no token equals no token. */
    public function matches(string $submitted, ?string $expected): bool
    {
        if ($submitted === '' || $expected === null || $expected === '') {
            return false;
        }

        return hash_equals($expected, $submitted);
    }

    /** What gets stored when this token becomes a session binding or a session's own token. */
    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Whether a submitted token is the one bound to this session.
     *
     * The session's copy is a hash, so the comparison is hash-against-hash and
     * the stored value is useless to anyone who reads it.
     */
    public function matchesSession(string $submitted, Session $session): bool
    {
        if ($submitted === '' || $session->csrfHash === null) {
            return false;
        }

        return hash_equals($session->csrfHash, $this->hash($submitted));
    }
}
