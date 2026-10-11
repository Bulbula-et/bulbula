<?php

declare(strict_types=1);

namespace Bulbula\Tests\Feature\Api;

use Bulbula\Tests\Support\ApplicationTestCase;
use JsonException;
use PHPUnit\Framework\Attributes\Test;

/**
 * The machine sign-in (`api-spec-v1.0.md` §3.1), through the real kernel.
 *
 * Bearer tokens, no cookies, and therefore no CSRF: the token is the whole
 * credential and the compiler of this test is that it only ever arrives in the
 * `Authorization` header.
 */
final class AuthEndpointTest extends ApplicationTestCase
{
    private string $database = '';

    private string $mail = '';

    protected function setUp(): void
    {
        parent::setUp();

        $temporary = sys_get_temp_dir() . '/bulbula-api-' . bin2hex(random_bytes(4));
        mkdir($temporary . '/mail', 0777, true);

        $this->database = $temporary . '/bulbula.sqlite';
        $this->mail = $temporary . '/mail';

        $this->migrate();
    }

    /** @return array<string, string> */
    protected function bootEnvironment(): array
    {
        return [
            'APP_ENV'             => 'local',
            'DB_DRIVER'           => 'sqlite',
            'DB_SQLITE_PATH'      => $this->database,
            'MAIL_TRANSPORT'      => 'local',
            'MAIL_LOCAL_PATH'     => $this->mail,
            // Requests here are about the *limit*, not the cooldown: without
            // this, the second request of every loop would be refused for the
            // wrong reason.
            'OTP_RESEND_COOLDOWN' => '0',
        ];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->mail . '/*.txt') ?: [] as $file) {
            unlink($file);
        }

        foreach ([$this->mail, $this->database] as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function requesting_a_code_answers_with_a_uniform_accepted_response(): void
    {
        $response = $this->post('/api/v1/auth/otp/request', ['email' => 'person@example.com']);

        self::assertSame(202, $response->status());
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertSame(['status' => 'sent'], $this->json($response));

        // The answer cannot reveal whether an account exists because there is
        // nothing in it that could (AU-2, E-5).
        self::assertStringNotContainsString('person@example.com', $response->body());
    }

    #[Test]
    public function verifying_a_code_returns_a_token_and_not_a_cookie(): void
    {
        $token = $this->signIn();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    #[Test]
    public function the_token_authenticates_sign_out(): void
    {
        $token = $this->signIn();

        $response = $this->post('/api/v1/auth/logout', [], headers: ['authorization' => 'Bearer ' . $token]);

        self::assertSame(204, $response->status());

        // And it is dead afterwards: the same token is no longer a session.
        $again = $this->post('/api/v1/auth/logout', [], headers: ['authorization' => 'Bearer ' . $token]);

        self::assertSame(401, $again->status());
    }

    #[Test]
    public function sign_out_without_a_token_is_401_rather_than_a_shrug(): void
    {
        $response = $this->post('/api/v1/auth/logout');

        self::assertSame(401, $response->status());
        self::assertSame('unauthenticated', $this->json($response)['error']['code']);
    }

    #[Test]
    public function a_token_that_was_never_issued_is_refused(): void
    {
        $response = $this->post('/api/v1/auth/logout', [], headers: ['authorization' => 'Bearer ' . str_repeat('b', 64)]);

        self::assertSame(401, $response->status());
    }

    #[Test]
    public function a_cookie_is_not_accepted_as_a_token(): void
    {
        // CS-3 the other way round: these endpoints are bearer-only, so a
        // session cookie cannot drive them.
        $token = $this->signIn();

        $response = $this->post('/api/v1/auth/logout', [], ['bulbula_session' => $token]);

        self::assertSame(401, $response->status(), 'the token must arrive in the header or not at all');
    }

    #[Test]
    public function a_wrong_code_returns_the_documented_error_envelope(): void
    {
        $this->post('/api/v1/auth/otp/request', ['email' => 'person@example.com']);

        $response = $this->post('/api/v1/auth/otp/verify', ['email' => 'person@example.com', 'code' => '000000']);

        self::assertSame(422, $response->status());

        $error = $this->json($response)['error'];

        self::assertSame('validation_failed', $error['code']);
        self::assertArrayHasKey('request_id', $error, 'E-3: always present');

        // E-2: the client is told nothing about *why* it failed in production.
        // The reason is in the log, where staff can read it and an attacker
        // cannot (OTP-8, AU-3).
        self::assertSame('Some of the information was not accepted.', $error['message']);
        self::assertStringNotContainsString('code', strtolower($error['message']));
    }

    #[Test]
    public function a_refused_request_carries_retry_after_and_discloses_no_limit(): void
    {
        // §1.5 and E-6: a 429 says how long to wait and nothing about the
        // ceiling it hit.
        $limit = 5;

        for ($attempt = 1; $attempt <= $limit; $attempt++) {
            $this->post('/api/v1/auth/otp/request', ['email' => 'person@example.com']);
        }

        $response = $this->post('/api/v1/auth/otp/request', ['email' => 'person@example.com']);

        self::assertSame(429, $response->status());
        self::assertArrayHasKey('Retry-After', $response->headers());
        self::assertGreaterThan(0, (int) $response->headers()['Retry-After']);

        // E-6: how long to wait is disclosed; how many are allowed is not.
        $message = $this->json($response)['error']['message'];

        self::assertSame('Too many requests. Please slow down and try again shortly.', $message);
        self::assertStringNotContainsString('limit', strtolower($message));
    }

    /** Request a code, read it where a person would, and exchange it for a token. */
    private function signIn(): string
    {
        $this->post('/api/v1/auth/otp/request', ['email' => 'person@example.com']);

        $response = $this->post(
            '/api/v1/auth/otp/verify',
            ['email' => 'person@example.com', 'code' => $this->deliveredCode()],
        );

        self::assertSame(201, $response->status(), 'the code should have been accepted');

        $payload = $this->json($response);

        self::assertArrayHasKey('expires_at', $payload);
        self::assertSame('api', $payload['surface']);

        return (string) $payload['token'];
    }

    private function deliveredCode(): string
    {
        $files = glob($this->mail . '/*.txt') ?: [];
        sort($files);

        self::assertNotSame([], $files, 'no message was delivered');

        if (preg_match('/\b(\d{6})\b/', (string) file_get_contents((string) end($files)), $matches) !== 1) {
            self::fail('The delivered message carries no code.');
        }

        return $matches[1];
    }

    /** @return array<string, mixed> */
    private function json(\Bulbula\Backend\Core\Response $response): array
    {
        try {
            $decoded = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            self::fail('The response is not JSON: ' . $response->body() . ' (' . $failure->getMessage() . ')');
        }

        self::assertIsArray($decoded);

        return $decoded;
    }

}
