<?php

declare(strict_types=1);

namespace Bulbula\Tests\Feature\Api;

use Bulbula\Backend\Core\Request;
use Bulbula\Backend\Core\Response;
use Bulbula\Tests\Support\ApplicationTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The probes are a contract with the deployment, not a debugging convenience:
 * liveness must not depend on the database, readiness must say which
 * dependency is unhappy, and neither may be cached or disclose anything about
 * the deployment.
 */
final class HealthEndpointTest extends ApplicationTestCase
{
    #[Test]
    public function liveness_answers_on_both_paths_with_a_correlation_id(): void
    {
        foreach (['/health', '/api/v1/health'] as $path) {
            $response = $this->get($path);

            self::assertSame(200, $response->status(), $path);
            self::assertSame('application/json; charset=utf-8', $response->headers()['Content-Type']);
            self::assertSame('no-store', $response->headers()['Cache-Control']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $response->headers()['X-Request-Id']);
            self::assertSame(['status' => 'pass'], $this->payload($response->body()));
        }
    }

    #[Test]
    public function readiness_reports_every_dependency_it_checked(): void
    {
        foreach (['/health/ready', '/api/v1/health/ready'] as $path) {
            $response = $this->get($path);

            self::assertSame(200, $response->status(), $path);

            $payload = $this->payload($response->body());

            self::assertSame('pass', $payload['status']);
            self::assertSame(['database' => 'ok', 'storage' => 'ok'], $payload['checks']);
        }
    }

    #[Test]
    public function readiness_answers_503_and_names_the_failing_check(): void
    {
        // Nothing is substituted: the environment is the seam, and it is the
        // same seam a deployment uses. A database path that cannot exist makes
        // readiness fail while liveness stays healthy — which is the whole
        // reason the two answers are separate.
        $response = $this->withEnvironment(
            ['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => '/nonexistent-directory/bulbula.sqlite'],
            fn (): Response => $this->newApplication()->kernel()->handle(new Request('GET', '/health/ready')),
        );

        self::assertSame(503, $response->status());

        $payload = $this->payload($response->body());

        self::assertSame('fail', $payload['status']);
        self::assertSame('error', $payload['checks']['database']);
        self::assertStringNotContainsString('nonexistent-directory', $response->body());
    }

    #[Test]
    public function an_unknown_api_path_returns_the_json_error_envelope(): void
    {
        $response = $this->get('/api/v1/does-not-exist');

        self::assertSame(404, $response->status());

        /** @var array{error: array{code: string, message: string, request_id: string}} $payload */
        $payload = $this->payload($response->body());

        self::assertSame('not_found', $payload['error']['code']);
        self::assertSame('We could not find that page.', $payload['error']['message']);
        self::assertArrayNotHasKey('debug', $payload['error']);
    }

    #[Test]
    public function a_wrong_method_on_a_known_path_says_which_methods_are_allowed(): void
    {
        $response = $this->post('/api/v1/health');

        self::assertSame(405, $response->status());
        self::assertSame('GET', $response->headers()['Allow']);
    }

    /** @return array<string, mixed> */
    private function payload(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
