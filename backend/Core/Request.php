<?php

declare(strict_types=1);

namespace Bulbula\Backend\Core;

/**
 * An immutable description of the incoming request.
 *
 * Nothing downstream touches $_GET, $_POST or $_SERVER; they are read once,
 * here, so the rest of the application is testable without a web server. Every
 * accessor is typed: a value from the outside world is text or it is nothing,
 * and no caller has to cast it.
 */
final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly ?string $clientIp = null,
        public readonly array $cookies = [],
    ) {
    }

    /**
     * @param list<string> $trustedProxies networks whose forwarded client address is believed
     *                                     (ClientAddress). Empty — the default — means the peer
     *                                     address is the client address.
     */
    public static function fromGlobals(array $trustedProxies = []): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = is_string($uri) ? $uri : '/';

        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        return new self(
            method: strtoupper(self::scalarString($_SERVER['REQUEST_METHOD'] ?? null, 'GET')),
            path: $path,
            query: self::stringKeyed($_GET),
            body: self::readBody(),
            headers: self::readHeaders(),
            clientIp: ClientAddress::resolve($_SERVER, $trustedProxies),
            cookies: self::readCookies(),
        );
    }

    /**
     * A body that is a JSON object or a form post. Anything else is an empty
     * body — a request cannot smuggle structure in by sending an array where an
     * object was expected.
     *
     * @return array<string, mixed>
     */
    private static function readBody(): array
    {
        $contentType = self::scalarString($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? null, '');

        if (!str_contains($contentType, 'application/json')) {
            return self::stringKeyed($_POST);
        }

        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw === false ? '' : $raw, true);

        return is_array($decoded) ? self::stringKeyed($decoded) : [];
    }

    /**
     * The cookies the browser sent. Read once, here, like everything else from
     * the outside world.
     *
     * @return array<string, string>
     */
    private static function readCookies(): array
    {
        $cookies = [];

        foreach ($_COOKIE as $name => $value) {
            $cookies[(string) $name] = self::scalarString($value, '');
        }

        return $cookies;
    }

    /** @return array<string, string> */
    private static function readHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with((string) $key, 'HTTP_')) {
                continue;
            }

            $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = self::scalarString($value, '');
        }

        return $headers;
    }

    /**
     * @param  array<mixed> $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $normalised = [];

        foreach ($values as $key => $value) {
            $normalised[(string) $key] = $value;
        }

        return $normalised;
    }

    private static function scalarString(mixed $value, string $default): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** The cookie value, or null when the browser did not send one. */
    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /** The bearer token from the Authorization header, if it is a bearer one. */
    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');

        if ($header === null || !str_starts_with(strtolower($header), 'bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    /** The input as text; a non-scalar value is the caller's default. */
    public function string(string $key, string $default = ''): string
    {
        return self::scalarString($this->input($key), $default);
    }

    /** The input as a trimmed string, for fields a person typed into. */
    public function trimmed(string $key, string $default = ''): string
    {
        return trim($this->string($key, $default));
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /** True for /api/*, a JSON content type, or a JSON Accept header. */
    public function wantsJson(): bool
    {
        if (str_starts_with($this->path, '/api/')) {
            return true;
        }

        if (str_contains(self::scalarString($this->header('content-type'), ''), 'application/json')) {
            return true;
        }

        return str_contains(self::scalarString($this->header('accept'), ''), 'application/json');
    }

    /**
     * Whether the connection is secure. A reverse proxy or load balancer sets
     * x-forwarded-proto; trusting it is only safe because the proxy is the only
     * thing that can reach the application in this deployment.
     */
    public function isSecure(): bool
    {
        if (strtolower((string) $this->header('x-forwarded-proto')) === 'https') {
            return true;
        }

        $https = $_SERVER['HTTPS'] ?? null;

        return is_string($https) && $https !== '' && $https !== 'off';
    }
}
