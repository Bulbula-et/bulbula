<?php

declare(strict_types=1);

namespace Bulbula\Tests\Support;

use RuntimeException;

/**
 * A Google key set and tokens signed with it, generated locally.
 *
 * The point is that the whole sign-in path — signature, audience, expiry,
 * claims — is exercised against real cryptography with no network and no
 * Google account. The verifier does not know the difference, which is exactly
 * the property worth having: the same code that passes these tests is what
 * runs against google.com's keys in production (GA-1, GA-6).
 */
final class GoogleTestKeys
{
    public const KID = 'bulbula-test-key';

    /**
     * @return array{private: string, jwks: string}
     */
    public static function generate(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            throw new RuntimeException('Could not generate a test key.');
        }

        $private = '';

        if (!openssl_pkey_export($key, $private)) {
            throw new RuntimeException('Could not export the test key.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('The test key has no RSA parameters.');
        }

        $jwks = json_encode([
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => self::KID,
                'n'   => self::base64($details['rsa']['n']),
                'e'   => self::base64($details['rsa']['e']),
            ]],
        ], JSON_THROW_ON_ERROR);

        return ['private' => $private, 'jwks' => $jwks];
    }

    /**
     * A claim set that verifies, unless a test overrides part of it.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function claims(string $clientId, int $issuedAt, array $overrides = []): array
    {
        return array_merge([
            'iss'            => 'https://accounts.google.com',
            'aud'            => $clientId,
            'sub'            => '117789012345678901234',
            'email'          => 'sara@example.com',
            'email_verified' => true,
            'name'           => 'Sara Bekele',
            'iat'            => $issuedAt,
            'exp'            => $issuedAt + 3600,
        ], $overrides);
    }

    /** @param array<string, mixed> $claims */
    public static function token(array $claims, string $privateKey, string $keyId = self::KID): string
    {
        $segments = self::segment(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $keyId])
            . '.' . self::segment($claims);

        $signature = '';

        if (!openssl_sign($segments, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the test token.');
        }

        return $segments . '.' . self::base64($signature);
    }

    /** @param array<string, mixed> $claims */
    private static function segment(array $claims): string
    {
        return self::base64(json_encode($claims, JSON_THROW_ON_ERROR));
    }

    private static function base64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
