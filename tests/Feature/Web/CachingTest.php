<?php

declare(strict_types=1);

namespace Bulbula\Tests\Feature\Web;

use Bulbula\Backend\Database\Connection;
use Bulbula\Tests\Support\ApplicationTestCase;
use Bulbula\Tests\Support\DirectoryScenario;
use Bulbula\Tests\Support\TestClock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the application lets a page be cached by (`performance-and-caching.md`
 * §6.1, HC-1, HC-7) — and the defect that put this file here.
 *
 * The application used to state no caching policy on its HTML pages, and a web
 * server fills that silence with a default of its own: on the development
 * host, LiteSpeed answers `public, max-age=2592000`. So `/sign-in` — a page
 * that hands out a CSRF token — was advertised as publicly cacheable for
 * thirty days, and every error page said the same. Nothing in this repository
 * set that header, and `php -S` never adds one, so no test in this suite could
 * see it.
 *
 * The floor is now the application's own: a response that does not choose a
 * policy is `no-store`. A page that is genuinely public opts in when its
 * caching lands, with a content-derived `ETag` — never by accident.
 */
final class CachingTest extends ApplicationTestCase
{
    private string $database = '';

    private DirectoryScenario $scenario;

    private TestClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = sys_get_temp_dir() . '/bulbula-caching-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->clock    = new TestClock(new DateTimeImmutable('2026-10-13 09:00:00', new DateTimeZone('UTC')));

        $this->migrate();

        $this->scenario = new DirectoryScenario(new Connection($this->application()->config()), $this->clock);
    }

    /** @return array<string, string> */
    protected function bootEnvironment(): array
    {
        return [
            'APP_ENV'        => 'local',
            'DB_DRIVER'      => 'sqlite',
            'DB_SQLITE_PATH' => $this->database,
        ];
    }

    protected function tearDown(): void
    {
        if (is_file($this->database)) {
            unlink($this->database);
        }

        parent::tearDown();
    }

    #[Test]
    public function the_homepage_is_not_cacheable_by_default(): void
    {
        $response = $this->get('/');

        self::assertSame(200, $response->status());
        self::assertSame('no-store', $response->headers()['Cache-Control'] ?? null);
    }

    #[Test]
    public function the_sign_in_page_that_hands_out_a_token_is_never_cached(): void
    {
        $response = $this->get('/sign-in');

        self::assertSame(200, $response->status());
        self::assertNotSame([], $response->cookies(), 'this is the page that starts the CSRF cookie');
        self::assertSame(
            'no-store',
            $response->headers()['Cache-Control'] ?? null,
            'a cached sign-in page replays a stale token and the form fails for no visible reason',
        );
    }

    #[Test]
    public function a_not_found_page_is_never_cached(): void
    {
        $response = $this->get('/nothing-here');

        self::assertSame(404, $response->status());
        self::assertSame(
            'no-store',
            $response->headers()['Cache-Control'] ?? null,
            'a 404 a cache keeps for a month would hide the page that appears later',
        );
    }

    #[Test]
    public function a_permanent_redirect_is_not_cached_until_it_says_so(): void
    {
        $this->scenario->publishedBusiness();
        $this->scenario->redirects->record('/b/tsegaye-pharmacy-bole', '/b/tsegaye-pharmacy', 'slug_change', $this->clock->now());

        $response = $this->get('/b/tsegaye-pharmacy-bole');

        self::assertSame(301, $response->status());
        self::assertSame('no-store', $response->headers()['Cache-Control'] ?? null);
    }
}
