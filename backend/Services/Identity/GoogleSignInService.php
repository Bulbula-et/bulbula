<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Identity;

use Bulbula\Backend\Domain\Identity\PendingLink;
use Bulbula\Backend\Domain\Identity\PrincipalType;
use Bulbula\Backend\Domain\Identity\Surface;
use Bulbula\Backend\Exceptions\ProviderCredentialRejectedException;
use Bulbula\Backend\Exceptions\RateLimitException;
use Bulbula\Backend\Integrations\Google\GoogleTokenVerifier;
use Bulbula\Backend\Repositories\Identity\CustomerRepository;
use Bulbula\Backend\Security\RateLimiter;
use Bulbula\Backend\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * Signing in with Google (C-30, auth-identity §4.1 and §5.1).
 *
 * The resolution order is the spec's, and it is short enough to hold in the
 * head — which is the point of it:
 *
 * 1. an identity for this Google account already exists → sign in to its Customer;
 * 2. otherwise, a Customer already holds this **verified** address → *pending
 *    link*: a code goes to that address, and the identity is attached only when
 *    it comes back (LK-6, D-13 closed 2026-10-11);
 * 3. otherwise → a new Customer, created here, with the identity attached.
 *
 * Nothing merges. Step 2 adds a way in to a Customer that already exists; it
 * never moves Saves or Reviews, and it never picks between two Customers.
 */
final class GoogleSignInService
{
    public function __construct(
        private readonly GoogleTokenVerifier $verifier,
        private readonly CustomerRepository $customers,
        private readonly RequestOtpService $codes,
        private readonly SessionService $sessions,
        private readonly RateLimiter $limiter,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string|null $sessionBinding ties a link code to the browser that asked (OTP-7); ignored when no confirmation is needed
     *
     * @throws ProviderCredentialRejectedException when the credential does not verify
     * @throws RateLimitException                  when this source has asked too often (AX-1)
     */
    public function signIn(
        string $credential,
        Surface $surface = Surface::Web,
        ?string $sessionBinding = null,
        ?string $sourceNetwork = null,
        ?string $userAgent = null,
    ): GoogleSignInResult {
        $token = $this->verifier->verify($credential);
        $now = $this->clock->now();

        // Google's availability is a dependency of this flow only (GA-6); the
        // limit is the same family as the OTP limits (AX-1) and is checked
        // after verification so that junk costs nothing but a signature check.
        if ($sourceNetwork !== null) {
            $decision = $this->limiter->check('google_signin', 'network:' . $sourceNetwork);

            if (!$decision->allowed) {
                $this->logger->warning('google.refused', ['reason' => 'network_limit', 'network' => $sourceNetwork]);

                throw new RateLimitException($decision->retryAfter);
            }
        }

        $existingCustomerId = $this->customers->findIdentity('google', $token->subject);

        if ($existingCustomerId !== null) {
            $customer = $this->customers->findById($existingCustomerId);

            if ($customer !== null) {
                $this->customers->touchLastSeen($customer->id, $now);

                return GoogleSignInResult::signedIn($this->start($customer->id, $surface, $userAgent, $sourceNetwork));
            }
        }

        $customer = $this->customers->findByEmail($token->email);

        if ($customer !== null) {
            $this->logger->info('google.confirmation_required', ['customer' => $customer->publicRef]);

            $this->codes->request(
                $token->email,
                $sessionBinding,
                $sourceNetwork,
                new PendingLink('google', $token->subject),
            );

            return GoogleSignInResult::confirmationRequired($token->email);
        }

        $customer = $this->customers->create($token->email, $now, $token->name);
        $this->customers->linkIdentity($customer->id, 'google', $token->subject, $token->email, $now);
        $this->customers->touchLastSeen($customer->id, $now);

        $this->logger->info('customer.created', ['customer' => $customer->publicRef, 'provider' => 'google']);

        return GoogleSignInResult::signedIn($this->start($customer->id, $surface, $userAgent, $sourceNetwork));
    }

    private function start(int $customerId, Surface $surface, ?string $userAgent, ?string $network): \Bulbula\Backend\Domain\Identity\IssuedSession
    {
        return $this->sessions->start(PrincipalType::Customer, $customerId, $surface, $userAgent, $network);
    }
}
