<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Core;

use Bulbula\Backend\Core\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private const SLUG = '[a-z0-9-]{1,80}';

    #[Test]
    public function it_matches_a_static_route(): void
    {
        $router = new Router();
        $router->get('/search', 'search-handler');

        $match = $router->match('GET', '/search');

        self::assertTrue($match->isFound());
        self::assertSame('search-handler', $match->handler);
        self::assertSame([], $match->parameters);
    }

    #[Test]
    public function it_matches_a_constrained_segment_and_captures_it(): void
    {
        $router = new Router();
        $router->get('/b/{business:' . self::SLUG . '}', 'business-handler');

        $match = $router->match('GET', '/b/kaldi-coffee');

        self::assertTrue($match->isFound());
        self::assertSame(['business' => 'kaldi-coffee'], $match->parameters);
    }

    #[Test]
    public function a_segment_outside_the_pattern_is_not_a_route(): void
    {
        $router = new Router();
        $router->get('/b/{business:' . self::SLUG . '}', 'business-handler');

        // Upper case, spaces or a traversal attempt never reach a handler.
        self::assertFalse($router->match('GET', '/b/Kaldi%20Coffee')->isFound());
        self::assertFalse($router->match('GET', '/b/..')->isFound());
    }

    #[Test]
    public function a_known_path_with_the_wrong_method_is_reported_as_such(): void
    {
        $router = new Router();
        $router->post('/reviews', 'review-handler');

        $match = $router->match('GET', '/reviews');

        self::assertTrue($match->isMethodNotAllowed());
        self::assertSame(['POST'], $match->allowedMethods);
    }

    #[Test]
    public function an_unknown_path_is_not_found(): void
    {
        $router = new Router();
        $router->get('/', 'home-handler');

        self::assertFalse($router->match('GET', '/nothing-here')->isFound());
    }
}
