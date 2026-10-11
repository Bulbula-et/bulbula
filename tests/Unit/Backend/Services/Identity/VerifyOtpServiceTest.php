<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Services\Identity;

use Bulbula\Backend\Domain\Identity\Email;
use Bulbula\Backend\Domain\Identity\Surface;
use Bulbula\Backend\Exceptions\OtpVerificationException;
use Bulbula\Backend\Repositories\Identity\CustomerRepository;
use Bulbula\Backend\Repositories\Identity\OtpRequestRepository;
use Bulbula\Backend\Repositories\Identity\SessionRepository;
use Bulbula\Backend\Repositories\Platform\RateLimitRepository;
use Bulbula\Backend\Security\RateLimiter;
use Bulbula\Backend\Services\Identity\RequestOtpService;
use Bulbula\Backend\Services\Identity\SessionService;
use Bulbula\Backend\Services\Identity\VerifyOtpService;
use Bulbula\Tests\Support\DatabaseTestCase;
use Bulbula\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;

/**
 * Turning a code into a session, against the rules that make it safe: single
 * use, an attempt ceiling, expiry, session binding, and one uniform answer for
 * every way it can fail (OTP-1 … OTP-10).
 */
final class VerifyOtpServiceTest extends DatabaseTestCase
{
    private RecordingLogger $logger;

    private CapturingOtpSender $sender;

    private RequestOtpService $requester;

    private VerifyOtpService $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger();
        $this->sender = new CapturingOtpSender();

        $requests = new OtpRequestRepository($this->database());
        $customers = new CustomerRepository($this->database());
        $sessions = new SessionService(new SessionRepository($this->database()), $this->config(), $this->clock);

        $this->requester = new RequestOtpService(
            $requests,
            $this->limiter(),
            $this->sender,
            $this->config(),
            $this->clock,
            $this->logger,
        );

        $this->verifier = new VerifyOtpService(
            $requests,
            $customers,
            $sessions,
            $this->limiter(),
            $this->config(),
            $this->clock,
            $this->logger,
        );
    }

    #[Test]
    public function a_correct_code_creates_the_customer_and_a_session(): void
    {
        $email = $this->ask('Person@Example.com');

        $issued = $this->verifier->verify($email, (string) $this->sender->lastCode());

        self::assertSame(1, $this->customerCount());
        self::assertSame('person@example.com', $this->onlyCustomerEmail(), 'the address is normalised once');
        self::assertNotNull($issued->session);
        self::assertSame(64, strlen($issued->token));

        // The session is real: the token resolves.
        self::assertNotNull(
            (new SessionService(new SessionRepository($this->database()), $this->config(), $this->clock))
                ->resolve($issued->token),
        );

        // And the email identity was linked, so this address now reaches this
        // Customer (C-30, C-32).
        $identity = $this->database()->pdo()
            ->query('SELECT provider, subject FROM provider_identities LIMIT 1')
            ->fetch();

        self::assertSame('email', $identity['provider']);
        self::assertSame('person@example.com', $identity['subject']);
    }

    #[Test]
    public function a_code_works_once(): void
    {
        $email = $this->ask('person@example.com');
        $code = (string) $this->sender->lastCode();

        $this->verifier->verify($email, $code);

        try {
            $this->verifier->verify($email, $code);
            self::fail('A consumed code must fail even inside its window (OTP-3).');
        } catch (OtpVerificationException) {
            // expected
        }

        self::assertSame(1, $this->sessionCount(), 'the second attempt did not create a second session');
    }

    #[Test]
    public function signing_in_again_reuses_the_same_customer(): void
    {
        $email = $this->ask('person@example.com');
        $this->verifier->verify($email, (string) $this->sender->lastCode());

        $this->clock->advance($this->config()->int('security.otp.resend_cooldown'));
        $this->ask('person@example.com');
        $this->verifier->verify($email, (string) $this->sender->lastCode());

        self::assertSame(1, $this->customerCount(), 'one address is one Customer (C-32)');
        self::assertSame(1, $this->identityCount(), 'and one identity for it');
        self::assertSame(2, $this->sessionCount(), 'while several sessions are normal (S-8)');
    }

    #[Test]
    public function every_failure_says_exactly_the_same_thing(): void
    {
        // OTP-8: the caller must not be able to tell "no such code" from
        // "wrong code" from "expired" — each would confirm something.
        $messages = [];

        $email = $this->ask('person@example.com');
        $messages[] = $this->failureMessage($email, '000000');

        $unknown = Email::fromString('nobody@example.com');
        $messages[] = $this->failureMessage($unknown, '123456');

        $this->clock->advance($this->config()->int('security.otp.ttl') + 1);
        $messages[] = $this->failureMessage($email, '123456');

        self::assertCount(1, array_unique($messages));
        self::assertSame('The code could not be verified.', $messages[0]);
    }

    #[Test]
    public function a_code_stops_working_once_it_has_expired(): void
    {
        $email = $this->ask('person@example.com');
        $code = (string) $this->sender->lastCode();

        $this->clock->advance($this->config()->int('security.otp.ttl') + 1);

        $this->expectException(OtpVerificationException::class);

        $this->verifier->verify($email, $code);
    }

    #[Test]
    public function a_few_wrong_guesses_discard_the_code_entirely(): void
    {
        // OTP-5: after the ceiling, even the *correct* code is dead — the next
        // step is a new request, which is itself rate limited.
        $email = $this->ask('person@example.com');
        $code = (string) $this->sender->lastCode();
        $ceiling = $this->config()->int('security.otp.max_attempts');

        for ($attempt = 1; $attempt <= $ceiling; $attempt++) {
            $this->failureMessage($email, '999999');
        }

        $this->expectException(OtpVerificationException::class);

        $this->verifier->verify($email, $code);
    }

    #[Test]
    public function a_code_asked_for_in_one_context_cannot_be_used_from_another(): void
    {
        // OTP-7: the code is bound to the context that requested it.
        $email = Email::fromString('person@example.com');
        $this->requester->request($email, sessionBinding: 'session-abc');
        $code = (string) $this->sender->lastCode();

        try {
            $this->verifier->verify($email, $code, sessionBinding: 'session-somewhere-else');
            self::fail('A code driven from a different context must fail (OTP-7).');
        } catch (OtpVerificationException) {
            // expected
        }

        // The same code, from the context that asked for it, works.
        $issued = $this->verifier->verify($email, $code, sessionBinding: 'session-abc');

        self::assertNotNull($issued->session);
    }

    #[Test]
    public function the_session_records_the_surface_it_was_created_on(): void
    {
        $email = $this->ask('person@example.com');

        $issued = $this->verifier->verify($email, (string) $this->sender->lastCode(), Surface::Telegram);

        self::assertSame(Surface::Telegram, $issued->session->surface);
    }

    #[Test]
    public function verification_is_rate_limited_too(): void
    {
        // OTP-6 covers verify as well as request: guessing is a quantity game,
        // and the ceiling applies before any code is even looked at.
        $email = $this->ask('person@example.com');
        $limit = (int) $this->config()->get('security.rate_limits.otp_verify')['limit'];

        for ($attempt = 1; $attempt <= $limit; $attempt++) {
            try {
                $this->verifier->verify($email, '000000');
            } catch (OtpVerificationException) {
                // expected: the wrong code is refused
            }
        }

        $this->expectException(\Bulbula\Backend\Exceptions\RateLimitException::class);

        $this->verifier->verify($email, (string) $this->sender->lastCode());
    }

    #[Test]
    public function no_code_and_no_address_reaches_the_log(): void
    {
        $email = $this->ask('person@example.com');
        $code = (string) $this->sender->lastCode();

        $this->failureMessage($email, '999999');

        $transcript = $this->logger->transcript();

        // The *reason* belongs in the log: that is how a person investigating
        // an account learns what happened. What must never be there is the
        // code, the guess, or the address.
        self::assertStringContainsString('otp.verify_failed', $transcript);
        self::assertStringContainsString('wrong_code', $transcript);

        self::assertStringNotContainsString($code, $transcript);
        self::assertStringNotContainsString('999999', $transcript);
        self::assertStringNotContainsString('person@example.com', $transcript);
    }

    private function ask(string $address): Email
    {
        $email = Email::fromString($address);
        $this->requester->request($email);

        return $email;
    }

    /** Verify, expecting a refusal, and hand back what the caller was told. */
    private function failureMessage(Email $email, string $code): string
    {
        try {
            $this->verifier->verify($email, $code);
        } catch (OtpVerificationException $failure) {
            return $failure->getMessage();
        }

        self::fail('Verification was expected to fail.');
    }

    private function limiter(): RateLimiter
    {
        return new RateLimiter(
            new RateLimitRepository($this->database()),
            $this->config(),
            $this->clock,
            $this->logger,
        );
    }

    private function customerCount(): int
    {
        return (int) $this->database()->pdo()->query('SELECT COUNT(*) FROM customers')->fetchColumn();
    }

    private function identityCount(): int
    {
        return (int) $this->database()->pdo()->query('SELECT COUNT(*) FROM provider_identities')->fetchColumn();
    }

    private function sessionCount(): int
    {
        return (int) $this->database()->pdo()->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
    }

    private function onlyCustomerEmail(): string
    {
        return (string) $this->database()->pdo()->query('SELECT email FROM customers LIMIT 1')->fetchColumn();
    }
}
