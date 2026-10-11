<?php

declare(strict_types=1);

namespace Bulbula\Tests\Support;

use Bulbula\Backend\Core\Application;
use Bulbula\Backend\Core\Cookie;
use Bulbula\Backend\Core\Kernel;
use Bulbula\Backend\Core\Request;
use Bulbula\Backend\Core\Response;
use PHPUnit\Framework\TestCase;

/**
 * Boots the real application, once, and drives it through the real kernel.
 *
 * Feature tests are not allowed to construct a handler by hand or to fake the
 * routing: the request goes in at the same point a web request would, so a
 * mistake in routing, error handling or response emission is caught here rather
 * than in production.
 */
abstract class ApplicationTestCase extends TestCase
{
    private ?Application $application = null;

    protected function tearDown(): void
    {
        // Application::boot() installs an error handler; PHPUnit must get its
        // own back or the next test inherits ours.
        restore_error_handler();

        $this->application = null;

        parent::tearDown();
    }

    protected function application(): Application
    {
        return $this->application ??= $this->boot();
    }

    /**
     * The kernel comes from the composition root that booted it, exactly as
     * `public/index.php` gets it, so a test fails when the real entry point
     * would fail.
     */
    protected function kernel(): Kernel
    {
        return $this->application()->kernel();
    }

    /**
     * A second, independent composition root.
     *
     * Used by tests that must change the environment *before* boot — the
     * environment is the only seam, and substituting a dependency afterwards
     * would test something the application cannot do.
     */
    protected function newApplication(?string $basePath = null): Application
    {
        $application = new Application($basePath ?? dirname(__DIR__, 2));
        $application->boot();

        return $application;
    }

    /**
     * Run a callback with environment values in force, restoring them after.
     *
     * @param array<string, string> $values
     */
    protected function withEnvironment(array $values, callable $callback): mixed
    {
        $previous = [];

        foreach ($values as $key => $value) {
            $previous[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = $value;
        }

        try {
            return $callback();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $value;
                }
            }
        }
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $cookies
     */
    protected function get(string $path, array $query = [], array $cookies = []): Response
    {
        return $this->kernel()->handle(new Request('GET', $path, query: $query, cookies: $cookies));
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    protected function post(string $path, array $body = [], array $cookies = [], array $headers = []): Response
    {
        return $this->kernel()->handle(
            new Request('POST', $path, body: $body, headers: $headers, cookies: $cookies, clientIp: '203.0.113.5'),
        );
    }

    /**
     * A cookie the response asked the browser to set, by name.
     *
     * A `Set-Cookie` header is the transport; this reads the object, so a test
     * asserts on attributes (HttpOnly, SameSite, Max-Age) rather than on a
     * string that happens to contain them.
     */
    protected function cookie(Response $response, string $name): ?Cookie
    {
        foreach ($response->cookies() as $cookie) {
            if ($cookie->name === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * Carry the cookies a response set into the next request, and return the
     * names as a browser would send them back.
     *
     * @return array<string, string>
     */
    protected function jar(Response $response, array $into = []): array
    {
        foreach ($response->cookies() as $cookie) {
            if ($cookie->value === '' || $cookie->maxAge < 0) {
                unset($into[$cookie->name]);

                continue;
            }

            $into[$cookie->name] = $cookie->value;
        }

        return $into;
    }

    /** The plaintext CSRF token a form would carry, taken from the response cookie. */
    protected function formToken(Response $response): string
    {
        return (string) ($this->cookie($response, 'bulbula_csrf')?->value ?? '');
    }

    /**
     * Environment values in force **while the application boots**.
     *
     * The environment is the only seam this application has, by design (R-3):
     * a test that needs a different database, mail transport or APP_ENV puts
     * the values here rather than reaching into a constructed object
     * afterwards — which would test something the application cannot do.
     *
     * @return array<string, string>
     */
    protected function bootEnvironment(): array
    {
        return [];
    }

    /**
     * Apply the migrations to the booted application's database.
     *
     * Feature tests that reach a table call this in `setUp()`; the migrations
     * are the ones the repository ships, not a fixture, so a schema mistake
     * fails here exactly as it would on a host.
     */
    protected function migrate(): void
    {
        $this->application()->migrator()->apply();
    }

    private function boot(): Application
    {
        // The environment window must cover boot(), not just construction:
        // `.env` and `config/*.php` are read during boot, and those reads are
        // what the environment values exist to change.
        return $this->withEnvironment(
            $this->bootEnvironment(),
            static function (): Application {
                $application = new Application(dirname(__DIR__, 2));
                $application->boot();

                return $application;
            },
        );
    }
}
