<?php

declare(strict_types=1);

namespace Bulbula\Backend\Support;

/**
 * TOTP (Time-based One-Time Password) implementation per RFC 6236.
 *
 * - 6-digit codes, 30-second time steps
 * - SHA-1 as the default hash (per the standard)
 * - Works without any external extensions; uses only PHP's hash() function.
 *
 * Usage:
 *   $totp = new TotpService($secret);
 *   $code = $totp->now();        // current code
 *   $verified = $totp->verify($code); // verify current or one step window
 */
final class TotpService
{
    private string $secret;
    private int $step;
    private int $digits;

    /** @param list<string> $allowedSteps permitted step sizes in seconds */
    public function __construct(string $secret, int $step = 30, int $digits = 6)
    {
        $this->secret = $secret;
        $this->step = $step;
        $this->digits = $digits;
    }

    /** Current Unix timestamp rounded down to the current step boundary */
    private function nowTimestamp(): int
    {
        return (int) (microtime(true) / $this->step) * $this->step;
    }

    /** Compute the TOTP value for the current time step */
    public function now(): string
    {
        $time = $this->nowTimestamp() / $this->step;
        $counter = pack('N*', $time); // big-endian 8-byte integer
        $hmac = hash_hmac('sha1', $counter, $this->secret, true);
        $offset = ord($hmac[strlen($hmac) - 1]);
        $binary = substr($hmac, $offset, 4);
        $code = (ord($binary[0]) & 0x7f) * 16777216
              + (ord($binary[1]) & 0xff) * 65536
              + (ord($binary[2]) & 0xff) * 256
              + (ord($binary[3]) & 0xff);
        $fmt = '%0' . $this->digits . 'd';
        return sprintf($fmt, $code % 1000000);
    }

    /** Verify a code against the current and adjacent time steps */
    public function verify(string $code, int $window = 1): bool
    {
        for ($i = -$window; $i <= $window; ++$i) {
            if ((string) $this->nowWithStep($i) === $code) {
                return true;
            }
        }
        return false;
    }

    private function nowWithStep(int $offset): string
    {
        $time = ($this->nowTimestamp() / $this->step) + $offset;
        $counter = pack('N*', $time);
        $hmac = hash_hmac('sha1', $counter, $this->secret, true);
        $offsetByte = ord($hmac[strlen($hmac) - 1]);
        $binary = substr($hmac, $offsetByte, 4);
        $code = (ord($binary[0]) & 0x7f) * 16777216
              + (ord($binary[1]) & 0xff) * 65536
              + (ord($binary[2]) & 0xff) * 256
              + (ord($binary[3]) & 0xff);
        $fmt = '%0' . $this->digits . 'd';
        return sprintf($fmt, $code % 1000000);
    }

    /** Generate a base32-encoded secret for QR code provisioning */
    public static function generateSecret(): string
    {
        // 160 bits = 20 bytes of randomness
        $random = random_bytes(20);
        return self::base32Encode($random);
    }

    private static function base32Encode(string $input): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $bits = 0;
        $value = 0;

        for ($i = 0; $i < strlen($input) * 8; $i += 5) {
            if ($i % 8 === 0) {
                $value = 0;
                $bits = 0;
            }
            $value |= ((ord($input[$i / 8]) >> ($i % 8)) & 1) << $bits;
            $bits++;
            if ($bits >= 5) {
                $output .= $alphabet[$value >> ($bits - 5)];
                $bits -= 5;
            }
        }
        // Pad to complete bytes
        while ($bits > 0) {
            $output .= $alphabet[$value >> ($bits - 5)] ?? '=';
            $bits -= 5;
        }
        return $output;
    }
}