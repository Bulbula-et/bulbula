<?php

declare(strict_types=1);

namespace Bulbula\Backend\Core;

/**
 * Which address a request really came from.
 *
 * `REMOTE_ADDR` is the only address a request cannot lie about: it is the peer
 * that opened the connection. But this project's deployment model puts a proxy
 * in front (`project-understanding-v0.1.md` — "hosting, Apache/LiteSpeed,
 * MariaDB, cron, Cloudflare in front"), and then the peer *is* the proxy, so
 * every visitor shares one address.
 *
 * That is not cosmetic. OTP-6, AX-1 and the API specification's rate limiting
 * are per **source network**, and the audit record names an actor's address: a
 * shared peer address would put every visitor in one rate-limit bucket (one
 * noisy client exhausting everybody's allowance) and would write the proxy into
 * the audit log as if it were the visitor.
 *
 * The rule here is deliberately conservative: a forwarded address is believed
 * **only** when the peer that sent it is one we already trust — a network listed
 * in `TRUSTED_PROXIES`. The header itself proves nothing: anyone can send it,
 * but only the proxy can be the sender. A request that reaches us directly,
 * whatever it claims in a header, is answered with its own address.
 */
final class ClientAddress
{
    /**
     * @param array<array-key, mixed> $server      the request's server variables
     * @param list<string>         $trustedProxies networks whose forwarding is believed, e.g. `2400:cb00::/32`
     */
    public static function resolve(array $server, array $trustedProxies): ?string
    {
        $peer = self::address($server['REMOTE_ADDR'] ?? null);

        if ($peer === null || $trustedProxies === [] || !self::isTrusted($peer, $trustedProxies)) {
            return $peer;
        }

        $chain = self::chain($server['HTTP_X_FORWARDED_FOR'] ?? null);

        if ($chain === []) {
            return $peer;
        }

        // Read right to left. Each proxy appends the address it saw, so the
        // rightmost entries are the ones added closest to us and the leftmost
        // may well have been typed by the client. The first entry that is not
        // itself a trusted proxy is the client; that is what makes a
        // client-supplied "X-Forwarded-For: 10.0.0.1" harmless — the real
        // address the proxy appended sits to its right.
        $chain[] = $peer;

        for ($index = count($chain) - 1; $index >= 0; $index--) {
            if (!self::isTrusted($chain[$index], $trustedProxies)) {
                return $chain[$index];
            }
        }

        // Every hop was a trusted proxy: the client is the leftmost address, or
        // the peer itself when the header carried nothing we could read.
        return $chain[0];
    }

    /** Whether an address sits inside a network written as `address` or `address/bits`. */
    public static function contains(string $network, string $address): bool
    {
        $network = trim($network);

        if ($network === '') {
            return false;
        }

        $parts = explode('/', $network, 2);
        $base = @inet_pton(trim($parts[0]));
        $candidate = @inet_pton($address);

        if ($base === false || $candidate === false || strlen($base) !== strlen($candidate)) {
            return false;
        }

        $maximum = strlen($base) * 8;
        $bits = isset($parts[1]) ? self::bits($parts[1], $maximum) : $maximum;

        if ($bits === null) {
            return false;
        }

        $whole = intdiv($bits, 8);
        $rest = $bits % 8;

        if ($whole > 0 && substr($base, 0, $whole) !== substr($candidate, 0, $whole)) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($base[$whole]) & $mask) === (ord($candidate[$whole]) & $mask);
    }

    /** @param mixed $value */
    private static function address(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }

    /**
     * The addresses named in a forwarded header, in the order they were written.
     * Anything that is not an address is dropped rather than guessed at.
     *
     * @param mixed $header
     *
     * @return list<string>
     */
    private static function chain(mixed $header): array
    {
        if (!is_string($header) || trim($header) === '') {
            return [];
        }

        $chain = [];

        foreach (explode(',', $header) as $candidate) {
            $address = self::address(trim($candidate));

            if ($address !== null) {
                $chain[] = $address;
            }
        }

        return $chain;
    }

    /** @param list<string> $trustedProxies */
    private static function isTrusted(string $address, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $network) {
            if (self::contains($network, $address)) {
                return true;
            }
        }

        return false;
    }

    /** A prefix length, or null when it is not a plain number inside the range. */
    private static function bits(string $value, int $maximum): ?int
    {
        $value = trim($value);

        if (preg_match('/^\d{1,3}$/', $value) !== 1) {
            return null;
        }

        $bits = (int) $value;

        return $bits <= $maximum ? $bits : null;
    }
}
