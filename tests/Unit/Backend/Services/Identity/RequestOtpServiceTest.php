<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Services\Identity;

use Bulbula\Backend\Domain\Identity\Email;
use Bulbula\Backend\Exceptions\RateLimitException;
use Bulbula\Backend\Repositories\Identity\OtpRequestRepository;
use Bulbula\Backend\Repositories\Platform\RateLimitRepository;
use Bulbula\Backend\Security\RateLimiter;
use Bulbula\Backend\Services\Identity\OtpSender;
use Bulbula\Backend\Services\Identity\RequestOtpService;
use Bulbula\Tests\Support\DatabaseTestCase;
use Bulbula\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;

/**
 * Issuing a sign-in code, against the rules that make it safe: a hash is
 * stored, the code goes only to the sender, and every request is limited by
 * address, by network, and by a cooldown (OTP-1 … OTP-10).
 */
final class RequestOtpServiceTest extends DatabaseTestCase
{
    private RecordingLogger $logger;

    private CapturingOtpSender $sender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger();
        $this->sender = new CapturingOtpSender();
    }

    #[Test]
    public function it_issues_a_code_the_sender_receives_and_the_database_never_sees(): void
    {
        $this->service()->request(Email::fromString('Person@Example.com'));

        $code = $this->sender->lastCode();
        $stored = $this->storedCodeHash();

        self::assertNotNull($code);
        self::assertMatchesRegularExpression('/^\d{6}$/', $code, 'six digits by configuration');
        self::assertNotSame($code, $stored, 'the code itself is never stored (OTP-2)');
        self::assertTrue(password_verify($code, $stored), 'the stored value is a hash of the code');
    }

    #[Test]
    public function a_second_request_for_the_same_address_waits_for_the_cooldown(): void
    {
        $service = $this->service();
        $email = Email::fromString('person@example.com');

        $service->request($email);

        try {
            $service->request($email);
            self::fail('A resend inside the cooldown must be refused (OTP-6).');
        } catch (RateLimitException) {
            // expected
        }

        $this->clock->advance($this->config()->int('security.otp.resend_cooldown'));
        $service->request($email);

        self::assertCount(2, $this->sender->sent);
    }

    #[Test]
    public function an_address_can_only_ask_so_often_in_a_window(): void
    {
        $service = $this->service();
        $email = Email::fromString('person@example.com');
        $cooldown = $this->config()->int('security.otp.resend_cooldown');

        // The configured policy is 5 per 900 seconds: five requests spaced past
        // the cooldown, then a refusal.
        for ($request = 1; $request <= 5; $request++) {
            $service->request($email);
            $this->clock->advance($cooldown);
        }

        $this->expectException(RateLimitException::class);

        $service->request($email);
    }

    #[Test]
    public function one_network_cannot_spray_many_addresses(): void
    {
        // OTP-6's second dimension: the ceiling applies to the source network,
        // not only to each address, which is what stops one host working
        // through a list of addresses.
        $service = $this->service();
        $cooldown = $this->config()->int('security.otp.resend_cooldown');

        for ($address = 1; $address <= 5; $address++) {
            $service->request(Email::fromString('person' . $address . '@example.com'), sourceNetwork: '203.0.113.7');
            $this->clock->advance($cooldown);
        }

        $this->expectException(RateLimitException::class);

        $service->request(Email::fromString('someone-else@example.com'), sourceNetwork: '203.0.113.7');
    }

    #[Test]
    public function a_network_marker_is_optional_and_its_absence_is_not_unlimited(): void
    {
        // An API client with no network context still meets the address limit.
        $service = $this->service();
        $email = Email::fromString('person@example.com');
        $cooldown = $this->config()->int('security.otp.resend_cooldown');

        for ($request = 1; $request <= 5; $request++) {
            $service->request($email);
            $this->clock->advance($cooldown);
        }

        $this->expectException(RateLimitException::class);

        $service->request($email);
    }

    #[Test]
    public function the_code_and_the_address_never_reach_the_log(): void
    {
        // OTP-10 and TR-135: a code in a log is a credential nobody rotates,
        // and an address is personal data.
        $this->service()->request(Email::fromString('person@example.com'), sourceNetwork: '203.0.113.7');

        $transcript = $this->logger->transcript();

        self::assertStringContainsString('otp.requested', $transcript);
        self::assertStringNotContainsString((string) $this->sender->lastCode(), $transcript);
        self::assertStringNotContainsString('person@example.com', $transcript);
    }

    #[Test]
    public function it_binds_the_code_to_the_context_that_asked_for_it(): void
    {
        $this->service()->request(Email::fromString('person@example.com'), sessionBinding: 'session-abc');

        $statement = $this->database()->pdo()
            ->query('SELECT session_binding FROM otp_requests ORDER BY id DESC LIMIT 1');

        self::assertSame('session-abc', (string) $statement->fetchColumn());
    }

    private function service(): RequestOtpService
    {
        return new RequestOtpService(
            new OtpRequestRepository($this->database()),
            new RateLimiter(
                new RateLimitRepository($this->database()),
                $this->config(),
                $this->clock,
                $this->logger,
            ),
            $this->sender,
            $this->config(),
            $this->clock,
            $this->logger,
        );
    }

    private function storedCodeHash(): string
    {
        $statement = $this->database()->pdo()->query('SELECT code_hash FROM otp_requests ORDER BY id DESC LIMIT 1');

        return (string) $statement->fetchColumn();
    }
}

/**
 * A sender that keeps what it was given, so the test can compare it with what
 * the database stored. Production has no equivalent — codes go to a transport
 * and are forgotten (OTP-10).
 */
final class CapturingOtpSender implements OtpSender
{
    /** @var list<array{email: string, code: string}> */
    public array $sent = [];

    public function send(Email $email, string $code): void
    {
        $this->sent[] = ['email' => $email->value, 'code' => $code];
    }

    public function lastCode(): ?string
    {
        return $this->sent === [] ? null : $this->sent[array_key_last($this->sent)]['code'];
    }
}
