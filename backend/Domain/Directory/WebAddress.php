<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Directory;

use InvalidArgumentException;

/**
 * A stored web address — a business website or a social link (APP-2.11).
 *
 * The rule is a scheme allow-list, not a filter: `http` and `https` are the
 * only schemes a directory address may carry, so `javascript:`, `data:`,
 * `vbscript:` and `file:` never reach the column that a page later turns into
 * an `href` (APP-2.12). Escaping protects the attribute; this protects the
 * scheme, which escaping cannot.
 *
 * The value is canonicalised before it is checked — trim, then lower-case the
 * scheme (APP-2.7) — so `HTTPS://Example.com` is stored as
 * `https://Example.com` rather than refused for a capital letter. A value
 * without a scheme is refused rather than guessed at: whether a business
 * means `http` or `https` is theirs to say.
 */
final readonly class WebAddress
{
    /** The `website` column is VARCHAR(255); the value may not outgrow it. */
    public const MAX_LENGTH = 255;

    /** The schemes a stored address may use. */
    private const SCHEMES = ['http', 'https'];

    /** A host name: letters, digits, dots and hyphens — no spaces, no angle brackets. */
    private const HOST = '/^[A-Za-z0-9.-]+$/';

    private function __construct(public string $value)
    {
    }

    /** @throws InvalidArgumentException when the value is not an http(s) address */
    public static function fromString(string $value): self
    {
        $candidate = trim($value);

        if ($candidate === '' || strlen($candidate) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A web address is 1 to ' . self::MAX_LENGTH . ' characters.');
        }

        $colon = strpos($candidate, ':');

        if ($colon === false) {
            throw new InvalidArgumentException('A web address begins with http:// or https://.');
        }

        $scheme    = strtolower(substr($candidate, 0, $colon));
        $candidate = $scheme . substr($candidate, $colon);

        if (!in_array($scheme, self::SCHEMES, true)) {
            throw new InvalidArgumentException('Only http and https addresses are accepted.');
        }

        $host = parse_url($candidate, PHP_URL_HOST);

        if (
            !is_string($host)
            || strlen($host) > 253
            || preg_match(self::HOST, $host) !== 1
            || (!str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false)
        ) {
            throw new InvalidArgumentException('A web address carries a host name, such as example.com.');
        }

        if (filter_var($candidate, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('That is not a valid web address.');
        }

        return new self($candidate);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
