<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Core;

use Bulbula\Backend\Core\CachePolicy;
use Bulbula\Backend\Core\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The floor under every response (`performance-and-caching.md` §6.1, HC-7).
 *
 * The defect this closes was invisible in this repository and only appeared on
 * a real host: LiteSpeed fills a missing `Cache-Control` with
 * `public, max-age=2592000`, so the sign-in page — which hands out a CSRF
 * token — was advertised as publicly cacheable for thirty days.
 */
final class CachePolicyTest extends TestCase
{
    #[Test]
    public function a_response_that_states_no_policy_gets_the_safe_one(): void
    {
        $response = CachePolicy::apply(Response::html('<p>hello</p>'));

        self::assertSame('no-store', $response->headers()['Cache-Control']);
    }

    #[Test]
    public function a_policy_the_response_chose_itself_is_left_alone(): void
    {
        $response = CachePolicy::apply(
            Response::json(['data' => []])->withHeader('Cache-Control', 'public, max-age=300'),
        );

        self::assertSame('public, max-age=300', $response->headers()['Cache-Control']);
    }

    #[Test]
    public function a_choice_is_recognised_whatever_case_it_is_written_in(): void
    {
        $response = CachePolicy::apply(
            Response::text('ok')->withHeader('cache-control', 'private'),
        );

        self::assertSame('private', $response->headers()['cache-control']);
        self::assertArrayNotHasKey('Cache-Control', $response->headers(), 'the policy is not stated twice');
    }
}
