<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Identity;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Domain\Identity\Email;
use Bulbula\Backend\Exceptions\RateLimitException;
use Bulbula\Backend\Repositories\Identity\OtpRequestRepository;
use Bulbula\Backend\Security\RateLimiter;
use Bulbula\Backend\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * Ask for a sign-in code (C-31, auth-identity §4.2).
 *
 * What this class is careful about:
 *
 * - **Nothing it returns says whether the address has an account.** The
 *   response is uniform either way (OTP-8); the account question is not even
 *   asked here (NA-1 — the account is created by verification, not by this).
 * - **The code is never stored, logged or returned.** Only a hash goes to the
 *   database (OTP-2) and only the sender sees the code itself (OTP-10).
 * - **It is rate limited twice over** — per address and per source network
 *   (OTP-6) — with a resend cooldown between requests for the same address.
 */
final class RequestOtpService
{
    public function __construct(
        private readonly OtpRequestRepository $requests,
        private readonly RateLimiter $limiter,
        private readonly OtpSender $sender,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string|null $sessionBinding an opaque marker tying the code to the context that asked (OTP-7)
     * @param string|null $sourceNetwork   the caller's network marker, for the second limit (OTP-6)
     *
     * @throws RateLimitException when the address, the network or the cooldown refuses the request
     */
    public function request(Email $email, ?string $sessionBinding = null, ?string $sourceNetwork = null): void
    {
        $now = $this->clock->now();
        $reference = $this->reference($email);

        $addressDecision = $this->limiter->check('otp_request', 'email:' . $email->value);

        if (!$addressDecision->allowed) {
            $this->logger->warning('otp.request_refused', ['reason' => 'address_limit', 'email' => $reference]);

            throw new RateLimitException($addressDecision->retryAfter);
        }

        if ($sourceNetwork !== null) {
            $networkDecision = $this->limiter->check('otp_request', 'network:' . $sourceNetwork);

            if (!$networkDecision->allowed) {
                $this->logger->warning('otp.request_refused', ['reason' => 'network_limit', 'network' => $sourceNetwork]);

                throw new RateLimitException($networkDecision->retryAfter);
            }
        }

        $cooldown = $this->config->int('security.otp.resend_cooldown', 60);
        $last = $this->requests->lastIssuedAt($email);

        if ($last !== null && $last->getTimestamp() + $cooldown > $now->getTimestamp()) {
            $this->logger->warning('otp.request_refused', ['reason' => 'cooldown', 'email' => $reference]);

            // The cooldown is a limit of the same family, and the wait is
            // exactly the time left on it.
            throw new RateLimitException($last->getTimestamp() + $cooldown - $now->getTimestamp());
        }

        $code = $this->generateCode();
        $ttl = $this->config->int('security.otp.ttl', 600);

        $this->requests->issue(
            $email,
            password_hash($code, PASSWORD_DEFAULT),
            $now,
            $now->modify('+' . $ttl . ' seconds'),
            $sessionBinding,
            $sourceNetwork,
        );

        $this->sender->send($email, $code);

        $this->logger->info('otp.requested', ['email' => $reference]);
    }

    /**
     * A CSPRNG code of the configured length (OTP-1).
     *
     * The length is clamped to a defensible range: below four digits a code is
     * not a secret worth the name, and above ten it stops being something a
     * person can read off a screen and type. The bounds are a guard against a
     * misconfigured value, not a policy of their own.
     */
    private function generateCode(): string
    {
        $length = max(4, min(10, $this->config->int('security.otp.length', 6)));
        $ceiling = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $ceiling), $length, '0', STR_PAD_LEFT);
    }

    /** A short, stable stand-in for an address, so a log line is not personal data (TR-135). */
    private function reference(Email $email): string
    {
        return substr(hash('sha256', $email->value), 0, 12);
    }
}
