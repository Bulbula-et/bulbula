<?php

declare(strict_types=1);

namespace Bulbula\Tests\Feature\Web;

use Bulbula\Backend\Core\Response;
use Bulbula\Tests\Support\ApplicationTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Signing in over the web surface, driven through the real kernel: the same
 * routing, the same composition root, the same cookies a browser would get.
 *
 * The mail transport is the local one, pointed at a temporary directory, so the
 * test reads the code exactly where a developer would — and nothing is sent.
 */
final class SignInFlowTest extends ApplicationTestCase
{
    private string $database = '';

    private string $mail = '';

    /** @var array<string, string> the cookies a browser would be holding */
    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $temporary = sys_get_temp_dir() . '/bulbula-web-' . bin2hex(random_bytes(4));
        mkdir($temporary . '/mail', 0777, true);

        $this->database = $temporary . '/bulbula.sqlite';
        $this->mail = $temporary . '/mail';

        $this->migrate();
    }

    /**
     * The real application, against a temporary database and mailbox.
     *
     * `APP_ENV=local` with the local mail transport is what makes the flow
     * completable at all: the code is delivered to a file this test reads,
     * exactly where a developer would find it, and nothing is sent anywhere.
     *
     * @return array<string, string>
     */
    protected function bootEnvironment(): array
    {
        return [
            'APP_ENV'             => 'local',
            'DB_DRIVER'           => 'sqlite',
            'DB_SQLITE_PATH'      => $this->database,
            'MAIL_TRANSPORT'      => 'local',
            'MAIL_LOCAL_PATH'     => $this->mail,
            // The resend cooldown is a *limit*, and limits have their own
            // coverage against the shipped numbers in
            // tests/Unit/Backend/Security/RateLimiterTest.php. Here it would
            // only stop the flow tests from asking twice.
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
    public function it_offers_a_form_with_a_csrf_token_in_a_hardened_cookie(): void
    {
        $response = $this->get('/sign-in');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Sign in', $response->body());
        self::assertStringContainsString('name="csrf"', $response->body());

        $token = $this->formToken($response);

        self::assertNotSame('', $token);
        self::assertStringContainsString($token, $response->body(), 'the form carries the token the cookie holds');

        $cookie = $this->cookie($response, 'bulbula_csrf');

        self::assertNotNull($cookie);
        self::assertTrue($cookie->httpOnly, 'K-1: never readable by JavaScript');
        self::assertSame('Lax', $cookie->sameSite, 'K-3');
        self::assertSame('/', $cookie->path, 'K-4: no cross-subdomain scope');
    }

    #[Test]
    public function a_post_without_the_token_is_refused_and_nothing_is_sent(): void
    {
        // CS-1: a state-changing request without the token is not from our page.
        $response = $this->post('/sign-in/code', ['email' => 'person@example.com']);

        self::assertSame(419, $response->status());
        self::assertSame([], glob($this->mail . '/*.txt') ?: [], 'no code was issued');
    }

    #[Test]
    public function a_post_with_a_token_that_does_not_match_the_cookie_is_refused(): void
    {
        $page = $this->get('/sign-in');
        $this->cookies = $this->jar($page);

        $response = $this->post(
            '/sign-in/code',
            ['email' => 'person@example.com', 'csrf' => str_repeat('a', 64)],
            $this->cookies,
        );

        self::assertSame(419, $response->status());
        self::assertSame([], glob($this->mail . '/*.txt') ?: []);
    }

    #[Test]
    public function a_valid_request_sends_a_code_and_asks_for_it(): void
    {
        $page = $this->get('/sign-in');
        $this->cookies = $this->jar($page);

        $response = $this->post(
            '/sign-in/code',
            ['email' => 'Person@Example.com', 'csrf' => $this->formToken($page)],
            $this->cookies,
        );

        self::assertSame(200, $response->status());
        self::assertStringContainsString('If that address can sign in, a code is on its way', $response->body());
        self::assertStringContainsString('name="code"', $response->body(), 'the page has moved to the code stage');

        $code = $this->deliveredCode();

        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        self::assertStringNotContainsString($code, $response->body(), 'the code never travels in the page');
    }

    #[Test]
    public function the_answer_is_the_same_whether_or_not_the_address_has_an_account(): void
    {
        // OTP-8 / AU-2. One address that now has an account, one that does not.
        $this->askForCode('known@example.com');
        $code = $this->deliveredCode();
        $this->post(
            '/sign-in/verify',
            ['email' => 'known@example.com', 'code' => $code, 'csrf' => $this->cookies['bulbula_csrf']],
            $this->cookies,
        );
        $this->cookies = [];

        $known = $this->askForCode('known@example.com');
        $unknown = $this->askForCode('never-seen@example.com');

        self::assertSame($known->status(), $unknown->status());
        self::assertSame(
            $this->withoutPerRequestEcho($known->body()),
            $this->withoutPerRequestEcho($unknown->body()),
            'the page reveals nothing about whether the account exists',
        );
    }

    /**
     * The parts of a page that are different for every request *by design*:
     * the CSRF token it carries and the address the person just typed. Nothing
     * else may differ between a request for an existing account and one for an
     * address nobody has ever used.
     */
    private function withoutPerRequestEcho(string $body): string
    {
        return (string) preg_replace(
            ['/name="csrf" value="[0-9a-f]+"/', '/name="email"[^>]*value="[^"]*"/', '/value="[^"]*@[^"]*"/'],
            ['name="csrf" value="X"', 'name="email" value="Y"', 'value="Y"'],
            $body,
        );
    }

    #[Test]
    public function a_wrong_code_says_one_thing_and_does_not_sign_anyone_in(): void
    {
        $this->askForCode('person@example.com');
        $this->deliveredCode();

        $response = $this->post(
            '/sign-in/verify',
            ['email' => 'person@example.com', 'code' => '000000', 'csrf' => $this->cookies['bulbula_csrf']],
            $this->cookies,
        );

        self::assertSame(200, $response->status());
        self::assertStringContainsString('That code could not be used', $response->body());
        self::assertNull($this->cookie($response, 'bulbula_session'), 'no session was issued');
    }

    #[Test]
    public function the_right_code_signs_in_and_rotates_the_csrf_token(): void
    {
        $this->askForCode('person@example.com');
        $code = $this->deliveredCode();
        $before = $this->cookies['bulbula_csrf'];

        $response = $this->post(
            '/sign-in/verify',
            ['email' => 'person@example.com', 'code' => $code, 'csrf' => $before],
            $this->cookies,
        );

        self::assertSame(302, $response->status());
        self::assertSame('/', $response->headers()['Location']);

        $session = $this->cookie($response, 'bulbula_session');
        $csrf = $this->cookie($response, 'bulbula_csrf');

        self::assertNotNull($session, 'the session cookie is set');
        self::assertTrue($session->httpOnly);
        self::assertSame('Lax', $session->sameSite);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $session->value);

        self::assertNotNull($csrf);
        self::assertNotSame($before, $csrf->value, 'K-6: the pre-authentication token is never promoted (S-5)');
    }

    #[Test]
    public function a_code_cannot_be_used_from_a_browser_that_did_not_ask_for_it(): void
    {
        // OTP-7: the code is bound to the requesting context. Another browser
        // holds a different CSRF token, so the binding does not match.
        $this->askForCode('person@example.com');
        $code = $this->deliveredCode();

        $otherBrowser = $this->get('/sign-in');
        $otherJar = $this->jar($otherBrowser);

        $response = $this->post(
            '/sign-in/verify',
            ['email' => 'person@example.com', 'code' => $code, 'csrf' => $otherJar['bulbula_csrf']],
            $otherJar,
        );

        self::assertStringContainsString('That code could not be used', $response->body());
        self::assertNull($this->cookie($response, 'bulbula_session'));
    }

    #[Test]
    public function signing_out_revokes_the_session_and_clears_both_cookies(): void
    {
        $this->signIn();

        $session = $this->cookies['bulbula_session'];
        $csrf = $this->cookies['bulbula_csrf'];

        $response = $this->post('/sign-out', ['csrf' => $csrf], $this->cookies);

        self::assertSame(302, $response->status());
        self::assertSame('', $this->cookie($response, 'bulbula_session')->value);
        self::assertLessThan(0, $this->cookie($response, 'bulbula_session')->maxAge);
        self::assertLessThan(0, $this->cookie($response, 'bulbula_csrf')->maxAge);

        // And the token really is dead server-side, not merely forgotten by
        // the browser (S-7).
        $this->cookies = $this->jar($response, $this->cookies);

        self::assertFalse($this->sessionIsLive($session));
    }

    #[Test]
    public function signing_out_without_the_token_is_refused(): void
    {
        $this->signIn();

        $response = $this->post('/sign-out', [], $this->cookies);

        self::assertSame(419, $response->status());
        self::assertTrue($this->sessionIsLive($this->cookies['bulbula_session']), 'the session survives');
    }

    #[Test]
    public function an_address_that_is_not_one_is_rejected_before_anything_is_sent(): void
    {
        $page = $this->get('/sign-in');
        $this->cookies = $this->jar($page);

        $response = $this->post(
            '/sign-in/code',
            ['email' => 'not-an-address', 'csrf' => $this->formToken($page)],
            $this->cookies,
        );

        self::assertSame(422, $response->status());
        self::assertSame([], glob($this->mail . '/*.txt') ?: []);
    }

    /** Ask for a code as a browser would, carrying the cookie jar forward. */
    private function askForCode(string $address): Response
    {
        $page = $this->get('/sign-in');
        $this->cookies = $this->jar($page, $this->cookies);

        $response = $this->post(
            '/sign-in/code',
            ['email' => $address, 'csrf' => $this->formToken($page)],
            $this->cookies,
        );

        $this->cookies = $this->jar($response, $this->cookies);

        return $response;
    }

    /** The full happy path, leaving the jar holding a live session. */
    private function signIn(): void
    {
        $this->askForCode('person@example.com');
        $code = $this->deliveredCode();

        $response = $this->post(
            '/sign-in/verify',
            ['email' => 'person@example.com', 'code' => $code, 'csrf' => $this->cookies['bulbula_csrf']],
            $this->cookies,
        );

        self::assertSame(302, $response->status(), 'the test needs a successful sign-in to continue');
        $this->cookies = $this->jar($response, $this->cookies);
    }

    /** The code from the newest delivered message — what a person would read. */
    private function deliveredCode(): string
    {
        $files = glob($this->mail . '/*.txt') ?: [];
        sort($files);

        self::assertNotSame([], $files, 'no message was delivered');

        $body = (string) file_get_contents((string) end($files));

        if (preg_match('/\b(\d{6})\b/', $body, $matches) !== 1) {
            self::fail('The delivered message carries no code: ' . $body);
        }

        return $matches[1];
    }

    private function sessionIsLive(string $token): bool
    {
        return $this->application()->sessions()->resolve($token) !== null;
    }
}
