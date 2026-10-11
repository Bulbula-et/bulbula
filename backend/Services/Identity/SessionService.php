<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Identity;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Domain\Identity\IssuedSession;
use Bulbula\Backend\Domain\Identity\PrincipalType;
use Bulbula\Backend\Domain\Identity\Session;
use Bulbula\Backend\Domain\Identity\Surface;
use Bulbula\Backend\Repositories\Identity\SessionRepository;
use Bulbula\Backend\Security\Csrf;
use Bulbula\Backend\Support\Clock;

/**
 * Sessions: one concept, two transports (TD-02).
 *
 * A token exists in plaintext exactly once — in the hands of whoever just
 * authenticated. Everything here deals in its hash (S-3), which is why
 * `resolve()` takes the token the client sent and looks up something else
 * entirely.
 *
 * Two lifetimes are enforced on every resolution (S-6): the inactivity window
 * from `last_active_at`, and the absolute cap in `expires_at`. A session that
 * fails either is over — and being over is a *read* decision here, not a
 * delete: revocation is explicit, so a session that simply aged out does not
 * have to be rewritten to be refused.
 */
final class SessionService
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Csrf $csrf,
    ) {
    }

    /** Issue a session and return the only plaintext copy of its token. */
    public function start(
        PrincipalType $principalType,
        int $principalId,
        Surface $surface,
        ?string $userAgent = null,
        ?string $network = null,
    ): IssuedSession {
        $now = $this->clock->now();
        $token = $this->newToken();

        // A CSRF token is issued with the session and stored as its hash, so
        // every later state-changing request can be checked against *this*
        // session rather than against any cookie the browser happens to hold
        // (CS-1, CS-4).
        $csrfToken = $this->csrf->newToken();

        $session = $this->sessions->create(
            $principalType,
            $principalId,
            $this->hash($token),
            $surface,
            $now,
            $now->modify('+' . $this->absoluteLifetime() . ' seconds'),
            $userAgent,
            $network,
            $this->csrf->hash($csrfToken),
        );

        return new IssuedSession($session, $token, $csrfToken);
    }

    /**
     * The session a token addresses, or null.
     *
     * Null covers every reason — unknown token, revoked, expired, idle too
     * long — because the caller has the same thing to do in each case: treat
     * the request as unauthenticated.
     */
    public function resolve(string $token, ?string $network = null): ?Session
    {
        if ($token === '') {
            return null;
        }

        $session = $this->sessions->findByTokenHash($this->hash($token));

        if ($session === null || $session->isRevoked()) {
            return null;
        }

        $now = $this->clock->now();

        if ($session->hasExpiredAt($now, $this->inactivityWindow())) {
            return null;
        }

        $this->sessions->touch($session->id, $now);

        return new Session(
            id: $session->id,
            principalType: $session->principalType,
            principalId: $session->principalId,
            surface: $session->surface,
            createdAt: $session->createdAt,
            lastActiveAt: $now,
            expiresAt: $session->expiresAt,
            revokedAt: null,
            csrfHash: $session->csrfHash,
        );
    }

    /** Sign one session out. Returns false when there was nothing live to revoke. */
    public function revoke(string $token): bool
    {
        $session = $this->sessions->findByTokenHash($this->hash($token));

        if ($session === null || $session->isRevoked()) {
            return false;
        }

        return $this->sessions->revoke($session->id, $this->clock->now());
    }

    /**
     * A new token for the same principal, with the old one dead in the same
     * breath — what a privilege change and an authentication both need
     * (S-5, K-6: a fresh identifier is issued, never promoted).
     */
    public function rotate(string $token): ?IssuedSession
    {
        $session = $this->resolve($token);

        if ($session === null) {
            return null;
        }

        $issued = $this->start($session->principalType, $session->principalId, $session->surface);

        $this->sessions->revoke($session->id, $this->clock->now());

        return $issued;
    }

    /** Everything this principal holds — what account deletion needs (S-7). */
    public function revokeAllFor(PrincipalType $principalType, int $principalId): int
    {
        return $this->sessions->revokeAllFor($principalType, $principalId, $this->clock->now());
    }

    /**
     * 256 bits from the CSPRNG, hex-encoded: opaque, unguessable, and with no
     * claim inside it that a client could read or alter (S-2, TR-105).
     */
    private function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function inactivityWindow(): int
    {
        return $this->config->int('security.session.lifetime', 1200);
    }

    private function absoluteLifetime(): int
    {
        $lifetime = $this->config->int('security.session.absolute_lifetime', 1209600);

        if ($lifetime <= 0) {
            throw new \RuntimeException('The session absolute lifetime must be a positive number of seconds.');
        }

        return $lifetime;
    }
}
