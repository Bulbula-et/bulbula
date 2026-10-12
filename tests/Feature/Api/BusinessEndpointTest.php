<?php

declare(strict_types=1);

namespace Bulbula\Tests\Feature\Api;

use Bulbula\Backend\Core\Request;
use Bulbula\Backend\Core\Response;
use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Domain\Directory\Slug;
use Bulbula\Tests\Support\ApplicationTestCase;
use Bulbula\Tests\Support\DirectoryScenario;
use Bulbula\Tests\Support\TestClock;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PHPUnit\Framework\Attributes\Test;

/**
 * `GET /api/v1/businesses/{slug}` (api-spec §2.3), through the real kernel.
 *
 * The contract this suite holds the endpoint to is the one the specification
 * writes as PR-1…PR-6: not-found is not-found for every private state, closure
 * is a status and not a removal, unrecorded data is absent rather than empty,
 * and no internal identifier ever leaves the building.
 */
final class BusinessEndpointTest extends ApplicationTestCase
{
    private string $database = '';

    private DirectoryScenario $scenario;

    private TestClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = sys_get_temp_dir() . '/bulbula-endpoint-' . bin2hex(random_bytes(4)) . '.sqlite';
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
    public function a_published_business_is_returned_under_data(): void
    {
        $this->scenario->publishedBusiness();

        $response = $this->get('/api/v1/businesses/tsegaye-pharmacy');

        self::assertSame(200, $response->status());

        $payload = $this->json($response);
        $data    = $payload['data'];

        self::assertSame('tsegaye-pharmacy', $data['slug']);
        self::assertSame('Tsegaye Pharmacy', $data['name']);
        self::assertSame('published', $data['status']);
        self::assertSame('unknown', $data['open_status'], 'hours are unrecorded, so the state is unknown — never "closed" (PR-5)');

        self::assertSame('pharmacies', $data['categories'][0]['slug']);
        self::assertTrue($data['categories'][0]['primary']);

        $branch = $data['branches'][0];

        self::assertTrue($branch['primary']);
        self::assertSame('bole-bulbula', $branch['area']['slug']);
        self::assertSame('Bole', $branch['sub_city']['name']);
        self::assertSame('Meskel Flower Road', $branch['address']);
        self::assertSame('+251911000000', $branch['contact']['phone']);
        self::assertSame(9.01, $branch['coordinates']['latitude']);

        $this->assertNoInternalIdentifiers($payload);
    }

    #[Test]
    public function a_closed_business_answers_with_its_status_rather_than_a_removal(): void
    {
        $business = $this->scenario->publishedBusiness();
        $this->scenario->businesses->markClosed($business->id, 'Business ceased trading', $this->clock->now(), 7);

        $payload = $this->json($this->get('/api/v1/businesses/tsegaye-pharmacy'));

        self::assertSame('closed', $payload['data']['status'], 'the URL and the history survive (TR-53, PR-2)');
    }

    #[Test]
    public function every_private_state_answers_the_same_not_found(): void
    {
        $draft = $this->scenario->business();
        $this->scenario->branch($draft);
        $this->scenario->businesses->assignCategory($draft->id, $this->scenario->category()->id, true, $this->clock->now());

        $unpublished = $this->scenario->publishedBusiness('Bole Dental', Slug::fromString('bole-dental'));
        $this->scenario->businesses->markUnpublished($unpublished->id, $this->clock->now(), 7);

        foreach (['/api/v1/businesses/tsegaye-pharmacy', '/api/v1/businesses/bole-dental', '/api/v1/businesses/never-created'] as $path) {
            $response = $this->get($path);

            self::assertSame(404, $response->status(), $path);
            self::assertSame('not_found', $this->json($response)['error']['code']);
            self::assertStringNotContainsString('Bole Dental', $response->body(), 'a refusal never describes what it is refusing (TR-35)');
        }
    }

    #[Test]
    public function unrecorded_data_is_omitted_rather_than_emptied(): void
    {
        $this->scenario->publishedBusiness();

        $data = $this->json($this->get('/api/v1/businesses/tsegaye-pharmacy'))['data'];

        self::assertArrayNotHasKey('website', $data);
        self::assertArrayNotHasKey('email', $data);
        self::assertArrayNotHasKey('social_links', $data);
        self::assertArrayNotHasKey('description', $data, 'no description is recorded, so there is no description object (PR-3)');
        self::assertArrayNotHasKey('label', $data['branches'][0], 'the single Branch carries no label (D-03)');
    }

    #[Test]
    public function a_moved_slug_is_not_redirected_on_the_api(): void
    {
        $this->scenario->publishedBusiness();
        $this->scenario->redirects->record('/b/tsegaye-pharmacy-bole', '/b/tsegaye-pharmacy', 'slug_change', $this->clock->now());

        $response = $this->get('/api/v1/businesses/tsegaye-pharmacy-bole');

        self::assertSame(404, $response->status(), 'a client follows canonical slugs; the API does not redirect (PR-1)');
    }

    #[Test]
    public function the_response_caches_publicly_and_revalidates_with_its_etag(): void
    {
        $this->scenario->publishedBusiness();

        $first = $this->get('/api/v1/businesses/tsegaye-pharmacy');
        $etag  = $first->headers()['ETag'] ?? null;

        self::assertNotNull($etag, 'a public read carries a content-derived validator (api-spec §1.8, HC-4)');
        self::assertStringContainsString('public, max-age=', (string) ($first->headers()['Cache-Control'] ?? ''));

        $second = $this->kernel()->handle(new Request(
            'GET',
            '/api/v1/businesses/tsegaye-pharmacy',
            headers: ['if-none-match' => $etag],
        ));

        self::assertSame(304, $second->status());
        self::assertSame('', $second->body());
    }

    #[Test]
    public function every_response_carries_the_correlation_id(): void
    {
        $this->scenario->publishedBusiness();

        $response = $this->get('/api/v1/businesses/tsegaye-pharmacy');

        self::assertNotSame('', (string) ($response->headers()['X-Request-Id'] ?? ''));
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        try {
            $decoded = json_decode(trim($response->body()), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail('The response was not JSON: ' . $exception->getMessage());
        }

        if (!is_array($decoded)) {
            self::fail('The response was not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** TR-25, DO-4: a public response carries no internal identifier, anywhere. */
    private function assertNoInternalIdentifiers(array $payload): void
    {
        foreach ($payload as $key => $value) {
            self::assertNotSame('id', $key, 'an internal id reached the public response');
            self::assertStringNotContainsString('_id', (string) $key, 'an internal id reached the public response');

            if (is_array($value)) {
                $this->assertNoInternalIdentifiers($value);
            }
        }
    }
}
