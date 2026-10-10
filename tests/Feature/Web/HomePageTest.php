<?php

declare(strict_types=1);

namespace Bulbula\Tests\Feature\Web;

use Bulbula\Tests\Support\ApplicationTestCase;
use PHPUnit\Framework\Attributes\Test;

final class HomePageTest extends ApplicationTestCase
{
    #[Test]
    public function the_homepage_renders_as_html(): void
    {
        $response = $this->get('/');

        self::assertSame(200, $response->status());
        self::assertSame('text/html; charset=utf-8', $response->headers()['Content-Type']);
        self::assertStringContainsString('Find a business in Addis Ababa you can trust.', $response->body());
    }

    #[Test]
    public function it_escapes_every_value_it_prints(): void
    {
        $response = $this->get('/search', ['q' => '<script>alert(1)</script>']);

        self::assertSame(200, $response->status());
        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body());
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $response->body());
    }

    #[Test]
    public function an_unknown_page_explains_itself_without_leaking_anything(): void
    {
        $response = $this->get('/nothing-here');

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Page not found', $response->body());
        self::assertStringNotContainsString('src/', $response->body());
        self::assertStringNotContainsString('Exception', $response->body());
    }
}
