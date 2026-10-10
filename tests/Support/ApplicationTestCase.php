<?php

declare(strict_types=1);

namespace Bulbula\Tests\Support;

use Bulbula\Backend\Core\Application;
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
    protected function newApplication(): Application
    {
        $application = new Application(dirname(__DIR__, 2));
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

    /** @param array<string, mixed> $query */
    protected function get(string $path, array $query = []): Response
    {
        return $this->kernel()->handle(new Request('GET', $path, query: $query));
    }

    /** @param array<string, mixed> $body */
    protected function post(string $path, array $body = []): Response
    {
        return $this->kernel()->handle(new Request('POST', $path, body: $body));
    }

    private function boot(): Application
    {
        $application = new Application(dirname(__DIR__, 2));
        $application->boot();

        return $application;
    }
}
