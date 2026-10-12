<?php

declare(strict_types=1);

namespace Bulbula\Backend\Core;

/**
 * The caching floor: no response leaves the application without a policy.
 *
 * A response that states no `Cache-Control` does not arrive without one — the
 * web server fills the gap with its own default. On the development host that
 * default is `public, max-age=2592000` (LiteSpeed, "access plus 1 month"), and
 * it landed on everything the application did not speak for: every HTML page,
 * every error page, and — until this class existed — `/sign-in`, which hands
 * out a CSRF token and was therefore marked *publicly cacheable for thirty
 * days*. A browser that cached it would replay a stale token, and the form
 * would fail validation for no visible reason.
 *
 * So the rule is the application's own, and it is the safe one
 * (`performance-and-caching.md` §6.1, HC-1, HC-7): no stated policy means
 * `no-store`. A response that is genuinely public opts in explicitly — the
 * profile already does, with a content-derived `ETag` — and nothing else is
 * cacheable by accident, on any host, whatever that host's default happens to
 * be.
 *
 * `Vary` is deliberately not set here. It names the inputs that change a
 * body, which is a property of a particular response, not of the floor
 * (HC-2).
 */
final class CachePolicy
{
    /** What a response that has not chosen gets: stored nowhere, by anyone. */
    public const DEFAULT = 'no-store';

    public static function apply(Response $response): Response
    {
        return self::decided($response)
            ? $response
            : $response->withHeader('Cache-Control', self::DEFAULT);
    }

    /** Header names are case-insensitive on the wire, so the check is too. */
    private static function decided(Response $response): bool
    {
        foreach (array_keys($response->headers()) as $name) {
            if (strcasecmp($name, 'Cache-Control') === 0) {
                return true;
            }
        }

        return false;
    }
}
