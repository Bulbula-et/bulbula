<?php

declare(strict_types=1);

namespace Bulbula\Backend\Core;

use JsonException;

/**
 * An immutable HTTP response. Handlers return one; the Kernel emits it.
 * Nothing in the application echoes output directly.
 */
final class Response
{
    /**
     * The four characters that can end an HTML context are hex-escaped, so a
     * JSON response embedded in a page cannot break out of it — `<script>`
     * arrives as `\u003Cscript\u003E`. Slashes and non-ASCII stay readable:
     * escaping them adds bytes without adding safety once `<`, `>`, `&`, `'`
     * and `"` are escaped.
     */
    private const JSON_FLAGS = JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    /**
     * @param array<string, string> $headers
     * @param list<Cookie>          $cookies
     */
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private readonly array $headers = [],
        private readonly array $cookies = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws JsonException never silently: a payload that cannot be encoded is
     *                       a bug in the handler, and the kernel renders it
     */
    public static function json(array $payload, int $status = 200): self
    {
        return new self(
            json_encode($payload, self::JSON_FLAGS),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    public function withHeader(string $name, string $value): self
    {
        $headers       = $this->headers;
        $headers[$name] = $value;

        return new self($this->body, $this->status, $headers, $this->cookies);
    }

    public function withStatus(int $status): self
    {
        return new self($this->body, $status, $this->headers, $this->cookies);
    }

    /**
     * Add a cookie to the response.
     *
     * Cookies are a list rather than part of the header map because one
     * response may set several — signing in sets the session cookie *and*
     * rotates the CSRF cookie — and a single `Set-Cookie` key would silently
     * drop all but the last.
     */
    public function withCookie(Cookie $cookie): self
    {
        return new self($this->body, $this->status, $this->headers, [...$this->cookies, $cookie]);
    }

    /** @return list<Cookie> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @throws JsonException when a JSON body cannot be decoded — never silently. */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }

            foreach ($this->cookies as $cookie) {
                header('Set-Cookie: ' . $cookie->toHeader(), false);
            }
        }

        echo $this->body;
    }
}
