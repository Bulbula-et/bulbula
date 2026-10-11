<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Integrations;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Exceptions\ProviderCredentialRejectedException;
use Bulbula\Backend\Integrations\Google\GoogleKeySet;
use Bulbula\Backend\Integrations\Google\GoogleTokenVerifier;
use Bulbula\Backend\Integrations\Google\StreamJsonFetcher;
use Bulbula\Tests\Support\GoogleTestKeys;
use Bulbula\Tests\Support\RecordingLogger;
use Bulbula\Tests\Support\TestClock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Google verifier, against keys this suite generated (GA-1…GA-3).
 *
 * No network, no Google account, and no way for a passing test to be a
 * temporary glitch in somebody else's service.
 */
final class GoogleTokenVerifierTest extends TestCase
{
    private const CLIENT = 'bulbula-test.apps.googleusercontent.com';

    private const ISSUED_AT = 1760000000;

    private string $directory = '';

    private string $privateKey = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bulbula-google-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0777, true);

        $keys = GoogleTestKeys::generate();
        $this->privateKey = $keys['private'];

        file_put_contents($this->directory . '/jwks.json', $keys['jwks']);

        $_ENV['GOOGLE_CLIENT_IDS'] = self::CLIENT;
        $_ENV['GOOGLE_JWKS_URL'] = 'file://' . $this->directory . '/jwks.json';
        $_ENV['GOOGLE_KEYS_CACHE'] = $this->directory . '/keys-cache.json';
    }

    protected function tearDown(): void
    {
        unset($_ENV['GOOGLE_CLIENT_IDS'], $_ENV['GOOGLE_JWKS_URL'], $_ENV['GOOGLE_KEYS_CACHE']);

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    #[Test]
    public function an_assertion_for_our_client_verifies_and_yields_the_subject_not_the_email(): void
    {
        $token = $this->verifier()->verify($this->signed());

        self::assertSame('117789012345678901234', $token->subject, 'GA-4: the subject is the stable key');
        self::assertSame('sara@example.com', $token->email->value);
        self::assertSame('Sara Bekele', $token->name);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function refusals(): iterable
    {
        yield 'another application’s token' => [['aud' => 'someone-else.apps.googleusercontent.com']];
        yield 'a token from another issuer' => [['iss' => 'https://accounts.example.com']];
        yield 'an expired token' => [['exp' => self::ISSUED_AT - 10]];
        yield 'a token issued in the future' => [['iat' => self::ISSUED_AT + 7200, 'exp' => self::ISSUED_AT + 10800]];
        yield 'a stale token' => [['iat' => self::ISSUED_AT - 7200, 'exp' => self::ISSUED_AT + 3600]];
        yield 'an address Google has not verified' => [['email_verified' => false]];
        yield 'a claim set with no subject' => [['sub' => '']];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[Test]
    #[DataProvider('refusals')]
    public function a_token_that_fails_any_check_is_refused(array $overrides): void
    {
        $this->expectException(ProviderCredentialRejectedException::class);

        $this->verifier()->verify($this->signed($overrides));
    }

    #[Test]
    public function a_tampered_payload_is_refused(): void
    {
        $token = $this->signed();
        [$header, , $signature] = explode('.', $token);
        $forged = $header . '.' . rtrim(strtr(base64_encode((string) json_encode([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT,
            'sub' => '117789012345678901234',
            'email' => 'attacker@example.com',
            'email_verified' => true,
            'iat' => self::ISSUED_AT,
            'exp' => self::ISSUED_AT + 3600,
        ])), '+/', '-_'), '=') . '.' . $signature;

        $this->expectException(ProviderCredentialRejectedException::class);

        $this->verifier()->verify($forged);
    }

    #[Test]
    public function an_unsigned_token_is_refused(): void
    {
        $header = rtrim(strtr(base64_encode((string) json_encode(['alg' => 'none', 'kid' => GoogleTestKeys::KID])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode((string) json_encode(GoogleTestKeys::claims(self::CLIENT, self::ISSUED_AT))), '+/', '-_'), '=');

        $this->expectException(ProviderCredentialRejectedException::class);

        $this->verifier()->verify($header . '.' . $payload . '.');
    }

    #[Test]
    public function a_key_id_google_does_not_publish_is_refused(): void
    {
        $token = GoogleTestKeys::token(
            GoogleTestKeys::claims(self::CLIENT, self::ISSUED_AT),
            $this->privateKey,
            'a-key-that-was-never-published',
        );

        $this->expectException(ProviderCredentialRejectedException::class);

        $this->verifier()->verify($token);
    }

    /** @param array<string, mixed> $overrides */
    private function signed(array $overrides = []): string
    {
        return GoogleTestKeys::token(
            GoogleTestKeys::claims(self::CLIENT, self::ISSUED_AT, $overrides),
            $this->privateKey,
        );
    }

    private function verifier(): GoogleTokenVerifier
    {
        $config = new Config(dirname(__DIR__, 4));
        $config->load('google');

        $clock = new TestClock(new DateTimeImmutable('@' . (self::ISSUED_AT + 60), new DateTimeZone('UTC')));

        return new GoogleTokenVerifier(
            new GoogleKeySet($config, $clock, new StreamJsonFetcher(), new RecordingLogger(), $this->directory),
            $config,
            $clock,
            new RecordingLogger(),
        );
    }
}
