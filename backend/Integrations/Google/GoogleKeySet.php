<?php

declare(strict_types=1);

namespace Bulbula\Backend\Integrations\Google;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Exceptions\ProviderCredentialRejectedException;
use Bulbula\Backend\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * Google's published signing keys (GA-1).
 *
 * A key set is fetched rarely and used on every sign-in, so it is cached: in
 * memory for the life of the request, and on disk for `google.keys_ttl`
 * seconds across requests. A stale cache is a *refusal* problem, not a
 * correctness one — an unknown key id is rejected, never guessed at.
 *
 * The conversion from JWK to PEM is done here rather than with a JWT library
 * because the operation is 30 lines of DER assembly and the alternative is a
 * dependency with its own history of signature-bypass advisories.
 */
final class GoogleKeySet
{
    /** @var array<string, string>|null */
    private ?array $keys = null;

    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly JsonFetcher $fetcher,
        private readonly LoggerInterface $logger,
        private readonly string $basePath,
    ) {
    }

    /** @throws ProviderCredentialRejectedException when the key id is not published */
    public function key(string $keyId): string
    {
        $keys = $this->keys();

        if (!array_key_exists($keyId, $keys)) {
            $this->logger->warning('google.key_unknown', ['kid' => $keyId]);

            throw new ProviderCredentialRejectedException();
        }

        return $keys[$keyId];
    }

    /** @return array<string, string> key id => PEM public key */
    private function keys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        $cached = $this->fromCache();

        if ($cached !== null) {
            return $this->keys = $cached;
        }

        $document = $this->fetcher->get($this->config->string('google.jwks_url'));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($document, true, flags: JSON_THROW_ON_ERROR);
        $this->writeCache($document);

        return $this->keys = $this->toPem($decoded);
    }

    /** @return array<string, string>|null */
    private function fromCache(): ?array
    {
        $path = $this->cachePath();

        if (!is_file($path)) {
            return null;
        }

        $document = file_get_contents($path);

        if ($document === false) {
            return null;
        }

        /** @var array<string, mixed> $cached */
        $cached = json_decode($document, true);

        $ttl = $this->config->int('google.keys_ttl', 3600);
        $fetchedAt = is_int($cached['fetched_at'] ?? null) ? $cached['fetched_at'] : 0;

        if ($fetchedAt + $ttl <= $this->clock->now()->getTimestamp()) {
            return null;
        }

        $raw = is_string($cached['jwks'] ?? null) ? $cached['jwks'] : '';

        if ($raw === '') {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true);

        return $this->toPem($decoded);
    }

    private function writeCache(string $jwks): void
    {
        $path = $this->cachePath();
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $this->logger->warning('google.cache_unwritable', ['path' => $directory]);

            return;
        }

        $payload = json_encode([
            'fetched_at' => $this->clock->now()->getTimestamp(),
            'jwks'       => $jwks,
        ]);

        if ($payload === false) {
            return;
        }

        // Written through a temporary file so a reader never sees half a key set.
        $temporary = $path . '.' . bin2hex(random_bytes(4));

        if (file_put_contents($temporary, $payload) === false) {
            return;
        }

        rename($temporary, $path);
    }

    private function cachePath(): string
    {
        $configured = $this->config->string('google.keys_cache', 'storage/tmp/keys/google.json');

        return str_starts_with($configured, '/') ? $configured : $this->basePath . '/' . $configured;
    }

    /**
     * @param array<string, mixed> $jwks
     *
     * @return array<string, string>
     */
    private function toPem(array $jwks): array
    {
        $keys = is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [];
        $pem = [];

        foreach ($keys as $key) {
            if (!is_array($key)) {
                continue;
            }

            $kid = is_string($key['kid'] ?? null) ? $key['kid'] : null;
            $modulus = is_string($key['n'] ?? null) ? $key['n'] : null;
            $exponent = is_string($key['e'] ?? null) ? $key['e'] : null;
            $type = is_string($key['kty'] ?? null) ? $key['kty'] : '';

            if ($kid === null || $modulus === null || $exponent === null || $type !== 'RSA') {
                continue;
            }

            $pem[$kid] = $this->pem($modulus, $exponent);
        }

        return $pem;
    }

    private function pem(string $modulus, string $exponent): string
    {
        $rsaKey = $this->sequence($this->integer($this->base64($modulus)) . $this->integer($this->base64($exponent)));
        // The AlgorithmIdentifier is already a complete DER sequence
        // (rsaEncryption + NULL); wrapping it again is the classic way to
        // produce a key that no library will parse.
        $algorithm = hex2bin('300d06092a864886f70d0101010500') ?: '';
        // SubjectPublicKeyInfo ends in a BIT STRING, not a nested SEQUENCE:
        // the RSA key goes inside one, with a zero "unused bits" octet first.
        $subjectPublicKeyInfo = $this->sequence($algorithm . $this->bitString($rsaKey));

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            . '-----END PUBLIC KEY-----';
    }

    private function bitString(string $body): string
    {
        return "\x03" . $this->length(strlen($body) + 1) . "\x00" . $body;
    }

    private function sequence(string $body): string
    {
        return "\x30" . $this->length(strlen($body)) . $body;
    }

    private function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '') {
            $bytes = "\x00";
        }

        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . $this->length(strlen($bytes)) . $bytes;
    }

    private function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function base64(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
