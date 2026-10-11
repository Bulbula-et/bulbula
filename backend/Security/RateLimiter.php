<?php

declare(strict_types=1);

namespace Bulbula\Backend\Security;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Exceptions\ConfigurationException;
use Bulbula\Backend\Repositories\Platform\RateLimitRepository;
use Bulbula\Backend\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * The policy half of rate limiting; the counting happens in the repository.
 *
 * The numbers live in `config/security.php` and never in a class constant, so
 * tightening a limit is a configuration change (TRD TR-196) — and they are
 * never disclosed in a response, which is why a refusal says nothing about how
 * long the caller should wait.
 *
 * **An unknown bucket is a configuration error, not an unlimited pass.** A
 * limit that silently does not apply is the failure mode this class exists to
 * prevent, so it fails closed and loudly.
 */
final class RateLimiter
{
    public function __construct(
        private readonly RateLimitRepository $limits,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Count one attempt and say whether it is allowed.
     *
     * @param string $bucket the policy name in `security.rate_limits`
     * @param string $key    what the limit applies to: an address, a network, an account
     *
     * @throws ConfigurationException when no policy is configured for the bucket
     */
    public function allows(string $bucket, string $key): bool
    {
        return $this->check($bucket, $key)->allowed;
    }

    /**
     * @throws ConfigurationException when no policy is configured for the bucket
     */
    public function check(string $bucket, string $key): RateLimitDecision
    {
        $policy = $this->config->get('security.rate_limits.' . $bucket);

        if (!is_array($policy) || !isset($policy['limit'], $policy['window'])) {
            throw new ConfigurationException(
                'No rate limit is configured for [' . $bucket . ']; refusing to treat it as unlimited.',
            );
        }

        if (!is_int($policy['limit']) || !is_int($policy['window'])) {
            throw new ConfigurationException(
                'The rate limit for [' . $bucket . '] must be a whole number of requests per whole number of seconds.',
            );
        }

        $hit = $this->limits->hit($bucket, $key, $policy['window'], $this->clock->now());

        if ($hit['hits'] <= $policy['limit']) {
            return new RateLimitDecision(true, $hit['retry_after']);
        }

        // OTP-9: the pattern is recorded for review. The key is hashed — an
        // address or a network is personal data, and a log is not the place
        // for it (TR-135).
        $this->logger->warning('rate limit exceeded', [
            'bucket' => $bucket,
            'key'    => substr(hash('sha256', $key), 0, 12),
            'hits'   => $hit['hits'],
        ]);

        return new RateLimitDecision(false, $hit['retry_after']);
    }
}
