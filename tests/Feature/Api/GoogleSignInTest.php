<?php

declare(strict_types=1);

namespace Bulbula\Tests\Feature\Api;

use Bulbula\Backend\Core\Response;
use Bulbula\Backend\Database\Connection;
use Bulbula\Tests\Support\ApplicationTestCase;
use Bulbula\Tests\Support\GoogleTestKeys;
use JsonException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Google sign-in over the API (C-30, GA-1…GA-6, LK-6).
 *
 * Everything runs against a key set this suite generated, so the checks are
 * about our rules rather than Google's availability.
 */
final class GoogleSignInTest extends ApplicationTestCase
{
    private const CLIENT = 'bulbula-api.apps.googleusercontent.com';

    private string $directory = '';

    private string $privateKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/bulbula-google-api-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/mail', 0777, true);

        $keys = GoogleTestKeys::generate();
        $this->privateKey = $keys['private'];

        file_put_contents($this->directory . '/jwks.json', $keys['jwks']);

        $this->migrate();
    }

    protected function tearDown(): void
    {
        // The application installs an error handler when it boots; PHPUnit
        // needs its own back before the next test runs.
        parent::tearDown();

        foreach (glob($this->directory . '/mail/*') ?: [] as $file) {
            unlink($file);
        }

        foreach (['/jwks.json', '/keys.json', '/mail', ''] as $path) {
            @unlink($this->directory . $path);
        }

        @rmdir($this->directory . '/mail');
        @rmdir($this->directory);
    }

    /** @return array<string, string> */
    protected function bootEnvironment(): array
    {
        return [
            'APP_ENV'             => 'local',
            'DB_DRIVER'           => 'sqlite',
            'DB_SQLITE_PATH'      => $this->directory . '/bulbula.sqlite',
            'MAIL_TRANSPORT'      => 'local',
            'MAIL_LOCAL_PATH'     => $this->directory . '/mail',
            'OTP_RESEND_COOLDOWN' => '0',
            'GOOGLE_CLIENT_IDS'   => self::CLIENT,
            'GOOGLE_JWKS_URL'     => 'file://' . $this->directory . '/jwks.json',
            'GOOGLE_KEYS_CACHE'   => $this->directory . '/keys.json',
        ];
    }

    #[Test]
    public function a_new_google_account_creates_a_customer_and_returns_a_session(): void
    {
        $response = $this->post('/api/v1/auth/google', ['credential' => $this->token()]);

        self::assertSame(201, $response->status());
        $body = $this->json($response);

        self::assertIsString($body['token']);
        self::assertSame('api', $body['surface']);

        $customers = $this->rows('SELECT id, email FROM customers');

        self::assertCount(1, $customers);
        self::assertSame('sara@example.com', $customers[0]['email']);

        $identities = $this->rows('SELECT provider, subject FROM provider_identities');

        self::assertCount(1, $identities);
        self::assertSame('google', $identities[0]['provider']);
        self::assertSame('117789012345678901234', $identities[0]['subject'], 'GA-4');
    }

    #[Test]
    public function the_same_google_account_returns_to_the_same_customer(): void
    {
        $this->post('/api/v1/auth/google', ['credential' => $this->token()]);
        $second = $this->post('/api/v1/auth/google', ['credential' => $this->token()]);

        self::assertSame(201, $second->status());
        self::assertCount(1, $this->rows('SELECT id FROM customers'));
        self::assertCount(1, $this->rows('SELECT id FROM provider_identities'));
    }

    #[Test]
    public function an_address_that_already_has_an_account_asks_for_confirmation_before_google_is_attached(): void
    {
        // An account exists, created the ordinary way: a code, entered.
        $this->post('/api/v1/auth/otp/request', ['email' => 'sara@example.com']);
        $signedIn = $this->post('/api/v1/auth/otp/verify', [
            'email' => 'sara@example.com',
            'code'  => $this->deliveredCode(),
        ]);

        self::assertSame(201, $signedIn->status());
        self::assertCount(1, $this->rows('SELECT id FROM provider_identities'));

        // Google asserts the same verified address — and is *not* attached (LK-6).
        $response = $this->post('/api/v1/auth/google', ['credential' => $this->token()]);

        self::assertSame(202, $response->status());
        self::assertSame('confirm_required', $this->json($response)['status']);
        self::assertCount(1, $this->rows('SELECT id FROM provider_identities'), 'no identity is attached yet');
        self::assertCount(0, $this->rows("SELECT id FROM provider_identities WHERE provider = 'google'"));

        // The code that proves the mailbox completes the link.
        $confirmed = $this->post('/api/v1/auth/otp/verify', [
            'email' => 'sara@example.com',
            'code'  => $this->deliveredCode(),
        ]);

        self::assertSame(201, $confirmed->status());

        $identities = $this->rows('SELECT customer_id, provider, subject FROM provider_identities');

        self::assertCount(2, $identities);
        self::assertSame($identities[0]['customer_id'], $identities[1]['customer_id'], 'one Customer, two ways in');
        self::assertCount(1, $this->rows('SELECT id FROM customers'));
    }

    #[Test]
    public function a_credential_that_does_not_verify_is_refused_with_one_message(): void
    {
        $response = $this->post('/api/v1/auth/google', ['credential' => 'not.a.token']);

        self::assertSame(401, $response->status());

        $error = $this->json($response)['error'];

        self::assertSame('unauthenticated', $error['code']);
        self::assertStringNotContainsString('signature', strtolower((string) $error['message']), 'GA-2');
    }

    #[Test]
    public function a_missing_credential_is_a_validation_failure(): void
    {
        $response = $this->post('/api/v1/auth/google', []);

        self::assertSame(422, $response->status());
        self::assertSame('credential', $this->json($response)['error']['details'][0]['field']);
    }

    #[Test]
    public function an_assertion_for_another_application_is_refused(): void
    {
        $response = $this->post('/api/v1/auth/google', [
            'credential' => $this->token(['aud' => 'somebody-else.apps.googleusercontent.com']),
        ]);

        self::assertSame(401, $response->status());
        self::assertCount(0, $this->rows('SELECT id FROM customers'), 'nothing is created from a foreign token');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function token(array $overrides = []): string
    {
        $now = time();

        return GoogleTestKeys::token(
            GoogleTestKeys::claims(self::CLIENT, $now, $overrides),
            $this->privateKey,
        );
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

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $connection = new Connection($this->application()->config());
        $statement = $connection->pdo()->query($sql);

        if ($statement === false) {
            self::fail('Query failed: ' . $sql);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /**
     * The code from a delivered message.
     *
     * @param list<string> $exclude files whose codes have already been used: two
     *                              messages can land in the same second, and mtime
     *                              alone cannot tell them apart
     */
    private function deliveredCode(array $exclude = []): string
    {
        $files = array_values(array_diff(glob($this->directory . '/mail/*.txt') ?: [], $exclude));

        if ($files === []) {
            self::fail('No message was delivered.');
        }

        usort(
            $files,
            static fn (string $a, string $b): int => [(int) filemtime($b), $b] <=> [(int) filemtime($a), $a],
        );

        $body = (string) file_get_contents($files[0]);

        if (preg_match('/\b(\d{6})\b/', $body, $matches) !== 1) {
            self::fail('The delivered message carried no code.');
        }

        return $matches[1];
    }

    /** @return list<string> */
    private function deliveredFiles(): array
    {
        return array_values(glob($this->directory . '/mail/*.txt') ?: []);
    }
}
