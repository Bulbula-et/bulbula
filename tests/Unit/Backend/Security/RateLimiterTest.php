<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Security;

use Bulbula\Backend\Exceptions\ConfigurationException;
use Bulbula\Backend\Repositories\Platform\RateLimitRepository;
use Bulbula\Backend\Security\RateLimiter;
use Bulbula\Tests\Support\DatabaseTestCase;
use Bulbula\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;

/**
 * The limiter against the real configured numbers: `otp_request` is 5 per 900
 * seconds, so five are allowed and the sixth is not.
 */
final class RateLimiterTest extends DatabaseTestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger();
    }

    #[Test]
    public function it_allows_up_to_the_configured_limit_and_refuses_the_next(): void
    {
        $limiter = $this->limiter();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            self::assertTrue($limiter->allows('otp_request', 'email:person@example.com'), 'attempt ' . $attempt);
        }

        self::assertFalse($limiter->allows('otp_request', 'email:person@example.com'));
    }

    #[Test]
    public function the_window_starts_at_the_first_hit_and_ends_on_time(): void
    {
        $limiter = $this->limiter();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $limiter->allows('otp_request', 'email:person@example.com');
        }

        self::assertFalse($limiter->allows('otp_request', 'email:person@example.com'));

        // One second short of the window: still refused.
        $this->clock->advance(899);
        self::assertFalse($limiter->allows('otp_request', 'email:person@example.com'));

        // The window has passed: counting starts again.
        $this->clock->advance(1);
        self::assertTrue($limiter->allows('otp_request', 'email:person@example.com'));
    }

    #[Test]
    public function one_key_does_not_consume_another(): void
    {
        $limiter = $this->limiter();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $limiter->allows('otp_request', 'email:first@example.com');
        }

        self::assertFalse($limiter->allows('otp_request', 'email:first@example.com'));
        self::assertTrue($limiter->allows('otp_request', 'email:second@example.com'));
    }

    #[Test]
    public function different_buckets_are_independent(): void
    {
        $limiter = $this->limiter();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $limiter->allows('otp_request', 'email:person@example.com');
        }

        self::assertTrue($limiter->allows('otp_verify', 'email:person@example.com'));
    }

    #[Test]
    public function a_bucket_with_no_policy_fails_loudly_rather_than_allowing_everything(): void
    {
        // An unconfigured limit that silently does not apply is the failure
        // this class exists to prevent: fail closed, and say why.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No rate limit is configured for [invented_bucket]');

        $this->limiter()->allows('invented_bucket', 'anything');
    }

    #[Test]
    public function it_does_not_log_the_key_it_limits(): void
    {
        // The key is an address or a network — personal data. Only a digest of
        // it may reach a log line (TR-135).
        $limiter = $this->limiter();

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $limiter->allows('otp_request', 'email:person@example.com');
        }

        self::assertStringContainsString('rate limit exceeded', $this->logger->transcript());
        self::assertStringNotContainsString('person@example.com', $this->logger->transcript());
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
}
