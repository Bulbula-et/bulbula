<?php

declare(strict_types=1);

namespace Bulbula\Backend\Core;

/**
 * A cookie, as the browser will receive it.
 *
 * The defaults are the hardened ones: `HttpOnly`, `SameSite=Lax`, and on a
 * `Secure` connection. A cookie that has to relax one of those has to say so at
 * the call site, which is what makes the relaxation visible in review
 * (K-1 … K-4).
 *
 * The value is URL-encoded on the way out, matching how PHP decodes `$_COOKIE`
 * on the way in. The tokens this application sets are hex and unaffected, but a
 * cookie whose correctness depended on its value staying alphanumeric would be
 * a trap.
 */
final readonly class Cookie
{
    public const SAME_SITE_LAX = 'Lax';

    public const SAME_SITE_STRICT = 'Strict';

    public function __construct(
        public string $name,
        public string $value,
        public int $maxAge = 0,
        public string $path = '/',
        public bool $secure = true,
        public bool $httpOnly = true,
        public string $sameSite = self::SAME_SITE_LAX,
    ) {
    }

    /**
     * A cookie that tells the browser to drop the name immediately.
     *
     * Removal in the response is the only way a cookie goes away: the server
     * never relies on a client forgetting a value it was told to keep.
     */
    public static function forget(string $name, string $path = '/', bool $secure = true): self
    {
        return new self($name, '', -1, $path, $secure);
    }

    public function toHeader(): string
    {
        $header = $this->name . '=' . urlencode($this->value)
            . '; Path=' . $this->path
            . '; Max-Age=' . $this->maxAge;

        if ($this->maxAge >= 0) {
            $header .= '; Expires=' . gmdate('D, d M Y H:i:s', time() + $this->maxAge) . ' GMT';
        }

        if ($this->secure) {
            $header .= '; Secure';
        }

        if ($this->httpOnly) {
            $header .= '; HttpOnly';
        }

        return $header . '; SameSite=' . $this->sameSite;
    }
}
