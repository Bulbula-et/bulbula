<?php

declare(strict_types=1);

namespace Bulbula\Tests\Feature\Web;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Domain\Directory\Slug;
use Bulbula\Tests\Support\ApplicationTestCase;
use Bulbula\Tests\Support\DirectoryScenario;
use Bulbula\Tests\Support\TestClock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;

/**
 * `/b/{business}`, through the real kernel (IA §2.1, public-web-ux §8).
 *
 * The three answers this address owes a stranger are all asserted here: the
 * profile, a permanent redirect when the slug moved (IAR-3), and a plain 404
 * for everything else — the same 404 for a draft, an unpublished record and a
 * typo, so the address never tells a stranger which records exist privately.
 */
final class ListingPageTest extends ApplicationTestCase
{
    private string $database = '';

    private DirectoryScenario $scenario;

    private TestClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = sys_get_temp_dir() . '/bulbula-listing-' . bin2hex(random_bytes(4)) . '.sqlite';
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
    public function a_published_business_renders_its_profile(): void
    {
        $this->scenario->publishedBusiness();

        $response = $this->get('/b/tsegaye-pharmacy');

        self::assertSame(200, $response->status());

        $body = $response->body();

        self::assertStringContainsString('Tsegaye Pharmacy', $body);
        self::assertStringContainsString('href="/c/pharmacies"', $body, 'the profile links out to its Category (IAR-15)');
        self::assertStringContainsString('href="/a/bole-bulbula"', $body, 'the profile links out to its Area (IAR-15)');
        self::assertStringContainsString('Meskel Flower Road', $body);
        self::assertStringContainsString('tel:+251911000000', $body);
        self::assertStringContainsString('google.com/maps', $body, 'coordinates degrade to an open-in-maps target (PR-6)');
        self::assertStringContainsString('Hours not confirmed', $body, 'unrecorded hours are never rendered as "Closed" (TR-56)');
        self::assertStringContainsString('aria-label="Breadcrumb"', $body);
        self::assertStringContainsString('<li aria-current="page">Tsegaye Pharmacy</li>', $body, 'the current page is not a link (IAR-19)');
    }

    #[Test]
    public function a_closed_business_keeps_its_page_and_states_its_status(): void
    {
        $business = $this->scenario->publishedBusiness();
        $this->scenario->businesses->markClosed($business->id, 'Business ceased trading', $this->clock->now(), 7);

        $response = $this->get('/b/tsegaye-pharmacy');

        self::assertSame(200, $response->status(), 'closure keeps the URL (TR-53, PR-2)');
        self::assertStringContainsString('permanently closed', strtolower($response->body()));
    }

    #[Test]
    public function a_draft_is_not_found(): void
    {
        $business = $this->scenario->business();
        $this->scenario->branch($business);
        $this->scenario->businesses->assignCategory($business->id, $this->scenario->category()->id, true, $this->clock->now());

        self::assertSame(404, $this->get('/b/tsegaye-pharmacy')->status());
    }

    #[Test]
    public function an_unpublished_listing_is_not_found(): void
    {
        $business = $this->scenario->publishedBusiness();
        $this->scenario->businesses->markUnpublished($business->id, $this->clock->now(), 7);

        self::assertSame(404, $this->get('/b/tsegaye-pharmacy')->status());
    }

    #[Test]
    public function an_unknown_address_is_not_found(): void
    {
        self::assertSame(404, $this->get('/b/never-created')->status());
    }

    #[Test]
    public function a_moved_slug_redirects_permanently(): void
    {
        $this->scenario->publishedBusiness();
        $this->scenario->redirects->record('/b/tsegaye-pharmacy-bole', '/b/tsegaye-pharmacy', 'slug_change', $this->clock->now());

        $response = $this->get('/b/tsegaye-pharmacy-bole');

        self::assertSame(301, $response->status(), 'a moved address answers permanently (IAR-3)');
        self::assertSame('/b/tsegaye-pharmacy', $response->headers()['Location'] ?? null);
    }

    #[Test]
    public function a_malformed_segment_never_reaches_a_controller(): void
    {
        foreach (['/b/Tsegaye-Pharmacy', '/b/tsegaye_pharmacy', '/b/-tsegaye-', '/b/tsegaye--pharmacy'] as $path) {
            self::assertSame(404, $this->get($path)->status(), $path . ' is not a URL Bulbula mints (IAR-4)');
        }
    }

    #[Test]
    public function a_segment_longer_than_a_slug_is_a_miss_not_an_error(): void
    {
        self::assertSame(404, $this->get('/b/' . str_repeat('a', Slug::MAX_LENGTH + 1))->status());
    }

    #[Test]
    public function stored_text_is_escaped_not_executed(): void
    {
        $this->scenario->publishedBusiness(
            'Tsegaye <script>alert(1)</script> Pharmacy',
            Slug::fromString('tsegaye-pharmacy'),
        );

        $body = $this->get('/b/tsegaye-pharmacy')->body();

        self::assertStringNotContainsString('<script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    #[Test]
    public function contact_actions_appear_only_where_a_contact_point_exists(): void
    {
        $this->scenario->publishedBusiness();

        $body = $this->get('/b/tsegaye-pharmacy')->body();

        self::assertStringNotContainsString('>Website<', $body, 'no disabled placeholder for a website nobody recorded (PR-4)');
    }
}
