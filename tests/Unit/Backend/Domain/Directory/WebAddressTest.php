<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Domain\Directory;

use Bulbula\Backend\Domain\Directory\WebAddress;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A stored address, before it is ever an `href` (APP-2.11, APP-2.12).
 *
 * The escaping in a template protects the attribute; nothing in a template can
 * protect the *scheme*. A `javascript:` value survives `htmlspecialchars()`
 * intact and runs when a visitor clicks the link, which is why the refusal
 * happens here, at the door, and not at the page.
 */
final class WebAddressTest extends TestCase
{
    #[Test]
    #[DataProvider('accepted')]
    public function an_http_address_is_accepted_and_canonicalised(string $given, string $expected): void
    {
        self::assertSame($expected, WebAddress::fromString($given)->value);
    }

    /** @return iterable<string, array{string, string}> */
    public static function accepted(): iterable
    {
        yield 'https'                => ['https://example.com', 'https://example.com'];
        yield 'http with a path'     => ['http://example.com/pharmacy', 'http://example.com/pharmacy'];
        yield 'surrounding spaces'   => ['  https://example.com  ', 'https://example.com'];
        yield 'an upper-case scheme' => ['HTTPS://Example.com', 'https://Example.com'];
        yield 'an explicit port'     => ['https://example.com:8443/x', 'https://example.com:8443/x'];
        yield 'an ipv4 host'         => ['http://196.188.10.4/', 'http://196.188.10.4/'];
        yield 'a query and fragment' => ['https://example.com/?a=1#top', 'https://example.com/?a=1#top'];
    }

    #[Test]
    #[DataProvider('refused')]
    public function anything_else_is_refused(string $given): void
    {
        $this->expectException(InvalidArgumentException::class);

        WebAddress::fromString($given);
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'javascript'           => ['javascript:alert(1)'];
        yield 'data'                 => ['data:text/html;base64,PHNjcmlwdD4='];
        yield 'vbscript'             => ['vbscript:msgbox(1)'];
        yield 'file'                 => ['file:///etc/passwd'];
        yield 'a protocol-relative'  => ['//example.com'];
        yield 'no scheme at all'     => ['example.com'];
        yield 'an empty value'       => [''];
        yield 'only whitespace'      => ['   '];
        yield 'a host without a dot' => ['https://localhost'];
        yield 'a space in the host'  => ['https://exa mple.com'];
        yield 'angle brackets'       => ['https://<script>'];
        yield 'longer than the column' => ['https://example.com/' . str_repeat('x', WebAddress::MAX_LENGTH)];
    }

    #[Test]
    public function a_refusal_never_repeats_the_offending_value(): void
    {
        try {
            WebAddress::fromString('javascript:alert(1)');
            self::fail('a javascript address is refused');
        } catch (InvalidArgumentException $refusal) {
            // APP-2.8: the message is safe to show and carry in a log.
            self::assertStringNotContainsString('alert', $refusal->getMessage());
            self::assertStringNotContainsString('javascript', $refusal->getMessage());
        }
    }
}
