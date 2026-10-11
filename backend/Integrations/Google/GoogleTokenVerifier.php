<?php

declare(strict_types=1);

namespace Bulbula\Backend\Integrations\Google;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Domain\Identity\Email;
use Bulbula\Backend\Exceptions\ProviderCredentialRejectedException;
use Bulbula\Backend\Support\Clock;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Verifies a Google ID token without trusting a word of it (GA-1…GA-3).
 *
 * The order matters. The signature is checked **first**, because nothing in the
 * payload means anything until the payload is known to be Google's; then the
 * claims that decide whether this token is for *us* (issuer, audience, times);
 * then the claim that decides whether the address may be used at all
 * (`email_verified`, GA-3).
 *
 * Refusals are uniform (GA-2, E-5). The reason is logged; the caller is told
 * that the sign-in did not work.
 */
final class GoogleTokenVerifier
{
    private const ALGORITHM = 'RS256';

    public function __construct(
        private readonly GoogleKeySet $keys,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @throws ProviderCredentialRejectedException */
    public function verify(string $credential): GoogleIdToken
    {
        $parts = explode('.', $credential);

        if (count($parts) !== 3) {
            $this->reject('malformed');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->decode($encodedHeader);
        $payload = $this->decode($encodedPayload);

        $algorithm = is_string($header['alg'] ?? null) ? $header['alg'] : '';
        $keyId = is_string($header['kid'] ?? null) ? $header['kid'] : '';

        // `alg` comes from the token, so it is attacker-controlled: only the
        // one algorithm Google uses is acceptable, and `none` never is.
        if ($algorithm !== self::ALGORITHM || $keyId === '') {
            $this->reject('unsupported_algorithm', ['alg' => $algorithm]);
        }

        $key = $this->keys->key($keyId);

        $signature = $this->base64UrlDecode($encodedSignature);
        $verified = openssl_verify(
            $encodedHeader . '.' . $encodedPayload,
            $signature,
            $key,
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            $this->reject('bad_signature');
        }

        $issuer = is_string($payload['iss'] ?? null) ? $payload['iss'] : '';

        if (!in_array($issuer, $this->list('google.issuers'), true)) {
            $this->reject('wrong_issuer', ['iss' => $issuer]);
        }

        $audiences = $this->audiences($payload['aud'] ?? null);
        $accepted = $this->list('google.client_ids');
        $audience = '';

        foreach ($audiences as $candidate) {
            if (in_array($candidate, $accepted, true)) {
                $audience = $candidate;
                break;
            }
        }

        if ($audience === '') {
            $this->reject('wrong_audience');
        }

        $now = $this->clock->now()->getTimestamp();
        $leeway = $this->config->int('google.clock_leeway', 60);

        $expiresAt = $this->time($payload['exp'] ?? null, $now + 1);
        $issuedAt = $this->time($payload['iat'] ?? null, $now);

        if ($expiresAt->getTimestamp() + $leeway <= $now) {
            $this->reject('expired');
        }

        $maxAge = $this->config->int('google.max_token_age', 600);

        if ($issuedAt->getTimestamp() - $leeway > $now) {
            $this->reject('issued_in_the_future');
        }

        // A credential is good for an hour; a *sign-in* is a moment. Old
        // assertions are refused so a captured token is not a standing key.
        if ($maxAge > 0 && $issuedAt->getTimestamp() + $maxAge + $leeway < $now) {
            $this->reject('stale');
        }

        $verifiedEmail = $payload['email_verified'] ?? null;

        // GA-3: an address Google has not verified is not an address here.
        if ($verifiedEmail !== true && $verifiedEmail !== 'true') {
            $this->reject('email_unverified');
        }

        $email = is_string($payload['email'] ?? null) ? $payload['email'] : '';
        $subject = is_string($payload['sub'] ?? null) ? $payload['sub'] : '';

        if ($email === '' || $subject === '') {
            $this->reject('incomplete');
        }

        try {
            $address = Email::fromString($email);
        } catch (InvalidArgumentException) {
            $this->reject('unusable_email');
        }

        return new GoogleIdToken(
            $subject,
            $address,
            $audience,
            $issuedAt,
            $expiresAt,
            is_string($payload['name'] ?? null) ? $payload['name'] : null,
            is_string($payload['picture'] ?? null) ? $payload['picture'] : null,
        );
    }

    /** @param array<string, mixed> $context */
    private function reject(string $reason, array $context = []): never
    {
        $this->logger->warning('google.credential_rejected', ['reason' => $reason] + $context);

        throw new ProviderCredentialRejectedException();
    }

    /** @return array<string, mixed> */
    private function decode(string $encoded): array
    {
        $document = $this->base64UrlDecode($encoded);
        /** @var mixed $decoded */
        $decoded = json_decode($document, true);

        if (!is_array($decoded)) {
            $this->reject('malformed');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return list<string> */
    private function audiences(mixed $claim): array
    {
        if (is_string($claim)) {
            return [$claim];
        }

        if (!is_array($claim)) {
            return [];
        }

        $audiences = [];

        foreach ($claim as $candidate) {
            if (is_string($candidate)) {
                $audiences[] = $candidate;
            }
        }

        return $audiences;
    }

    /** @return list<string> a comma-separated configuration value */
    private function list(string $key): array
    {
        $values = [];

        foreach (explode(',', $this->config->string($key)) as $value) {
            $value = trim($value);

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function time(mixed $claim, int $fallback): DateTimeImmutable
    {
        if (is_int($claim)) {
            return (new DateTimeImmutable('@' . $claim))->setTimezone(new \DateTimeZone('UTC'));
        }

        if (is_string($claim) && ctype_digit($claim)) {
            return (new DateTimeImmutable('@' . $claim))->setTimezone(new \DateTimeZone('UTC'));
        }

        return (new DateTimeImmutable('@' . $fallback))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            $this->reject('malformed');
        }

        return $decoded;
    }
}
