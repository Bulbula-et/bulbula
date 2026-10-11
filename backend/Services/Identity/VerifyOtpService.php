<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Identity;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Domain\Identity\Customer;
use Bulbula\Backend\Domain\Identity\Email;
use Bulbula\Backend\Domain\Identity\IssuedSession;
use Bulbula\Backend\Domain\Identity\OtpChallenge;
use Bulbula\Backend\Domain\Identity\PrincipalType;
use Bulbula\Backend\Domain\Identity\Surface;
use Bulbula\Backend\Exceptions\OtpVerificationException;
use Bulbula\Backend\Exceptions\RateLimitException;
use Bulbula\Backend\Repositories\Identity\CustomerRepository;
use Bulbula\Backend\Repositories\Identity\OtpRequestRepository;
use Bulbula\Backend\Security\RateLimiter;
use Bulbula\Backend\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * Exchange a code for a session (C-31, auth-identity §4.2).
 *
 * The whole class is built around one rule: **the caller learns one thing** —
 * verified, or not. Wrong code, expired code, already-used code, exhausted
 * attempts and a code driven from the wrong session all leave by the same door
 * with the same words (OTP-8). The reason is written to the log, where staff
 * can see it and an attacker cannot.
 *
 * The account is created here, not at request time (NA-1): a verified address
 * is what makes a Customer, and the first successful verification is the
 * registration step the product does not have (AI-1, C-30).
 */
final class VerifyOtpService
{
    public function __construct(
        private readonly OtpRequestRepository $requests,
        private readonly CustomerRepository $customers,
        private readonly SessionService $sessions,
        private readonly RateLimiter $limiter,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws OtpVerificationException when the code cannot be accepted, for any reason
     * @throws RateLimitException      when this source has tried too often (OTP-6)
     */
    public function verify(
        Email $email,
        string $code,
        Surface $surface = Surface::Web,
        ?string $sessionBinding = null,
        ?string $sourceNetwork = null,
        ?string $userAgent = null,
    ): IssuedSession {
        $now = $this->clock->now();
        $reference = $this->reference($email);

        $addressDecision = $this->limiter->check('otp_verify', 'email:' . $email->value);

        if (!$addressDecision->allowed) {
            $this->logger->warning('otp.verify_refused', ['reason' => 'address_limit', 'email' => $reference]);

            throw new RateLimitException($addressDecision->retryAfter);
        }

        if ($sourceNetwork !== null) {
            $networkDecision = $this->limiter->check('otp_verify', 'network:' . $sourceNetwork);

            if (!$networkDecision->allowed) {
                $this->logger->warning('otp.verify_refused', ['reason' => 'network_limit', 'network' => $sourceNetwork]);

                throw new RateLimitException($networkDecision->retryAfter);
            }
        }

        $challenge = $this->requests->latestUnconsumed($email);

        if ($challenge === null) {
            $this->logger->warning('otp.verify_failed', ['reason' => 'no_code', 'email' => $reference]);

            throw new OtpVerificationException();
        }

        $ceiling = $this->config->int('security.otp.max_attempts', 5);

        if ($challenge->hasExpiredAt($now)) {
            $this->requests->consume($challenge->id, $now);
            $this->logger->warning('otp.verify_failed', ['reason' => 'expired', 'email' => $reference]);

            throw new OtpVerificationException();
        }

        if (!$challenge->mayStillBeAttempted($ceiling)) {
            $this->requests->consume($challenge->id, $now);
            $this->logger->warning('otp.verify_failed', ['reason' => 'attempts_exhausted', 'email' => $reference]);

            throw new OtpVerificationException();
        }

        if ($challenge->sessionBinding !== null && $challenge->sessionBinding !== $sessionBinding) {
            $this->logger->warning('otp.verify_failed', ['reason' => 'wrong_context', 'email' => $reference]);

            throw new OtpVerificationException();
        }

        if (!password_verify($code, $challenge->codeHash)) {
            $this->failedAttempt($challenge, $ceiling, $reference);

            throw new OtpVerificationException();
        }

        // Single use (OTP-3): the UPDATE is conditional, so losing a race to
        // another request means failing here rather than signing in twice.
        if (!$this->requests->consume($challenge->id, $now)) {
            $this->logger->warning('otp.verify_failed', ['reason' => 'already_consumed', 'email' => $reference]);

            throw new OtpVerificationException();
        }

        $customer = $this->customerFor($email, $now, $reference);

        $issued = $this->sessions->start(
            PrincipalType::Customer,
            $customer->id,
            $surface,
            $userAgent,
            $sourceNetwork,
        );

        $this->logger->info('otp.verified', ['customer' => $customer->publicRef, 'surface' => $surface->value]);

        return $issued;
    }

    /**
     * The Customer this verified address belongs to, created on first sight.
     *
     * This is not account *linking* (that is D-13, still open): an email code
     * proves an address, and the address is the account's own verified
     * identifier. If it already resolves to a Customer, this signs in to that
     * Customer and touches nothing else.
     */
    private function customerFor(Email $email, \DateTimeImmutable $now, string $reference): Customer
    {
        $customer = $this->customers->findByEmail($email);

        if ($customer === null) {
            $customer = $this->customers->create($email, $now);

            $this->logger->info('customer.created', ['customer' => $customer->publicRef, 'email' => $reference]);
        }

        if ($this->customers->findIdentity('email', $email->value) === null) {
            $this->customers->linkIdentity($customer->id, 'email', $email->value, $email, $now);
        }

        $this->customers->touchLastSeen($customer->id, $now);

        return $customer;
    }

    /**
     * Count the failure, and throw the code away once the ceiling is reached
     * (OTP-5): a code that has been guessed at a few times stops being a code
     * that can be guessed at all.
     */
    private function failedAttempt(OtpChallenge $challenge, int $ceiling, string $reference): void
    {
        $this->requests->recordFailedAttempt($challenge->id);

        if ($challenge->attempts + 1 >= $ceiling) {
            $this->requests->consume($challenge->id, $this->clock->now());
            $this->logger->warning('otp.verify_failed', ['reason' => 'attempts_exhausted', 'email' => $reference]);

            return;
        }

        $this->logger->warning('otp.verify_failed', ['reason' => 'wrong_code', 'email' => $reference]);
    }

    private function reference(Email $email): string
    {
        return substr(hash('sha256', $email->value), 0, 12);
    }
}
