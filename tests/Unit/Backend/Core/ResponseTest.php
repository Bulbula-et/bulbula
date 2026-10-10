<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Core;

use Bulbula\Backend\Core\Response;
use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    #[Test]
    public function json_hex_escapes_every_character_that_can_end_an_html_context(): void
    {
        $response = Response::json(['name' => '<script>alert("x") & \'y\'</script>']);

        // The payload is JSON, and it is still safe if something embeds it in HTML.
        self::assertStringNotContainsString('<script>', $response->body());
        self::assertStringContainsString('\\u003Cscript\\u003E', $response->body());
        self::assertStringNotContainsString('alert("x")', $response->body());
        self::assertSame('/home', self::decode(Response::json(['path' => '/home'])->body())['path']);
    }

    #[Test]
    public function json_keeps_slashes_and_non_ascii_readable(): void
    {
        $response = Response::json(['area' => 'Bole', 'note' => 'አዲስ አበባ']);

        self::assertStringContainsString('"area":"Bole"', $response->body());
        self::assertStringContainsString('አዲስ አበባ', $response->body());
    }

    #[Test]
    public function json_declares_its_content_type_and_charset(): void
    {
        self::assertSame(
            'application/json; charset=utf-8',
            Response::json([])->headers()['Content-Type'],
        );
    }

    #[Test]
    public function a_response_is_immutable(): void
    {
        $original = Response::json(['status' => 'pass']);

        $changed = $original->withHeader('Cache-Control', 'no-store')->withStatus(503);

        self::assertSame(200, $original->status());
        self::assertArrayNotHasKey('Cache-Control', $original->headers());
        self::assertSame(503, $changed->status());
        self::assertSame('no-store', $changed->headers()['Cache-Control']);
    }

    #[Test]
    public function the_simple_shapes_are_there_for_the_cases_that_need_them(): void
    {
        self::assertSame(204, Response::noContent()->status());
        self::assertSame('', Response::noContent()->body());
        self::assertSame('/search', Response::redirect('/search')->headers()['Location']);
        self::assertSame(302, Response::redirect('/search')->status());
        self::assertSame('text/plain; charset=utf-8', Response::text('hi')->headers()['Content-Type']);
    }

    #[Test]
    public function a_payload_that_cannot_be_encoded_fails_loudly(): void
    {
        $this->expectException(JsonException::class);

        // Invalid UTF-8 cannot be represented in JSON, and silently emitting an
        // empty object would hide a real bug in the caller.
        Response::json(['broken' => "\xB1\x31"]);
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
