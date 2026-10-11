<?php

declare(strict_types=1);

namespace Bulbula\Tests\Feature\Web;

use Bulbula\Backend\Core\Cookie;
use Bulbula\Backend\Core\Response;
use Bulbula\Backend\Database\Connection;
use Bulbula\Tests\Support\ApplicationTestCase;
use Bulbula\Tests\Support\GoogleTestKeys;
use PHPUnit\Framework\Attributes\Test;

/**
 * Google sign-in in a browser (C-30, GA-1…GA-6, CS rules, LK-6).
 *
 * The page carries Google's own button; Google posts the credential back to us
 * as a form POST, which is why this endpoint is a POST and why Google's
 * double-submit pair is checked when Google sends one.
 */
final class GoogleSignInTest extends ApplicationTestCase
{
    private const CLIENT = 'bulbula-web.apps.googleusercontent.com';

    private string $directory = '';

    private string $privateKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/bulbula-google-web-' . bin2hex(random_bytes(4));
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

        @unlink($this->directory . '/jwks.json');
        @unlink($this->directory . '/keys.json');
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
    public function the_page_offers_google_only_when_a_client_id_is_configured(): void
    {
        $response = $this->get('/sign-in');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('g_id_signin', $response->body());
        self::assertStringContainsString(self::CLIENT, $response->body());
        self::assertStringContainsString('/sign-in/google', $response->body());
    }

    #[Test]
    public function a_matching_google_credential_signs_the_person_in(): void
    {
        $response = $this->googlePost($this->token());

        self::assertSame(302, $response->status());
        self::assertSame('/', $response->headers()['Location'] ?? '');

        $session = $this->cookie($response, 'bulbula_session');

        self::assertInstanceOf(Cookie::class, $session);
        self::assertNotSame('', $session->value);
        self::assertSame('google', $this->rows('SELECT provider FROM provider_identities')[0]['provider']);
    }

    #[Test]
    public function google_csrf_pair_mismatch_is_refused(): void
    {
        // Google sends one copy in a cookie and one in the body; an attacker can
        // forge neither, so a mismatch means the POST did not come from a page
        // Google rendered for this browser.
        $response = $this->post(
            '/sign-in/google',
            ['credential' => $this->token(), 'g_csrf_token' => 'the-body-copy'],
            ['g_csrf_token' => 'a-different-cookie'],
        );

        self::assertSame(419, $response->status());
        self::assertCount(0, $this->rows('SELECT id FROM provider_identities'));
    }

    #[Test]
    public function an_existing_account_is_asked_to_confirm_and_the_code_completes_the_link(): void
    {
        // Sara already has an account: she signed in with an email code.
        $page = $this->get('/sign-in');
        $cookies = $this->jar($page);
        $asked = $this->post(
            '/sign-in/code',
            ['email' => 'sara@example.com', 'csrf' => $this->formToken($page)],
            $cookies,
        );
        $signedIn = $this->post(
            '/sign-in/verify',
            ['email' => 'sara@example.com', 'code' => $this->deliveredCode(), 'csrf' => $this->formToken($asked)],
            $cookies,
        );

        self::assertSame(302, $signedIn->status());
        $cookies = $this->jar($signedIn, $cookies);

        $firstMessage = $this->deliveredFiles();

        // Google asserts the same address: not attached, a code is sent instead.
        $offered = $this->post(
            '/sign-in/google',
            ['credential' => $this->token(), 'g_csrf_token' => 'pair'],
            ['g_csrf_token' => 'pair'] + $cookies,
        );

        self::assertSame(200, $offered->status());
        self::assertStringContainsString('already has a Bulbula account', $offered->body());
        self::assertStringContainsString('name="code"', $offered->body(), 'the page moves to the code stage');
        self::assertCount(0, $this->rows("SELECT id FROM provider_identities WHERE provider = 'google'"));

        // The code proves the mailbox, and that is what attaches Google.
        $cookies = $this->jar($offered, $cookies);
        $linked = $this->post(
            '/sign-in/verify',
            [
                'email' => 'sara@example.com',
                'code'  => $this->deliveredCode($firstMessage),
                'csrf'  => $this->formToken($offered),
            ],
            $cookies,
        );

        self::assertSame(302, $linked->status());

        $identities = $this->rows('SELECT customer_id, provider FROM provider_identities');

        self::assertCount(2, $identities);
        self::assertSame($identities[0]['customer_id'], $identities[1]['customer_id'], 'one Customer, two ways in');
        self::assertCount(1, $this->rows('SELECT id FROM customers'));
    }

    private function googlePost(string $credential): Response
    {
        return $this->post(
            '/sign-in/google',
            ['credential' => $credential, 'g_csrf_token' => 'pair'],
            ['g_csrf_token' => 'pair'],
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function token(array $overrides = []): string
    {
        return GoogleTestKeys::token(
            GoogleTestKeys::claims(self::CLIENT, time(), $overrides),
            $this->privateKey,
        );
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
