<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Core;

use Bulbula\Backend\Core\ClientAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Who the request says it is, and who it really is.
 *
 * The per-network limits (OTP-6, AX-1) and the audit record depend on this
 * answer, and the deployment model puts a proxy in front — so the interesting
 * cases are the ones where a header disagrees with the connection.
 */
final class ClientAddressTest extends TestCase
{
    #[Test]
    public function the_peer_address_is_the_client_when_nothing_is_trusted(): void
    {
        self::assertSame('203.0.113.9', ClientAddress::resolve(['REMOTE_ADDR' => '203.0.113.9'], []));
    }

    #[Test]
    public function a_forwarded_header_from_an_untrusted_peer_is_ignored(): void
    {
        // The header proves nothing on its own: anyone can type it. Only being
        // sent by the proxy makes it worth reading.
        $server = [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1',
        ];

        self::assertSame('203.0.113.9', ClientAddress::resolve($server, ['198.51.100.0/24']));
    }

    #[Test]
    public function a_trusted_proxy_is_believed(): void
    {
        $server = [
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ];

        self::assertSame('203.0.113.9', ClientAddress::resolve($server, ['198.51.100.0/24']));
    }

    #[Test]
    public function a_client_supplied_prefix_does_not_become_the_client(): void
    {
        // What a forged request looks like: the client writes a value of its own,
        // and the proxy appends the address it actually saw, to the right.
        $server = [
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.9',
        ];

        self::assertSame('203.0.113.9', ClientAddress::resolve($server, ['198.51.100.0/24']));
    }

    #[Test]
    public function a_chain_of_trusted_proxies_resolves_to_the_visitor(): void
    {
        $server = [
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 198.51.100.7',
        ];

        self::assertSame(
            '203.0.113.9',
            ClientAddress::resolve($server, ['10.0.0.0/8', '198.51.100.0/24']),
        );
    }

    #[Test]
    public function junk_in_the_header_is_dropped_rather_than_guessed_at(): void
    {
        $server = [
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => 'unknown, 203.0.113.9, ::not-address::',
        ];

        self::assertSame('203.0.113.9', ClientAddress::resolve($server, ['198.51.100.0/24']));
    }

    #[Test]
    public function a_trusted_proxy_that_forwards_nothing_returns_the_peer(): void
    {
        self::assertSame(
            '198.51.100.7',
            ClientAddress::resolve(['REMOTE_ADDR' => '198.51.100.7'], ['198.51.100.0/24']),
        );
    }

    #[Test]
    public function a_peer_that_is_not_an_address_is_no_client(): void
    {
        self::assertNull(ClientAddress::resolve([], ['198.51.100.0/24']));

        self::assertNull(ClientAddress::resolve(['REMOTE_ADDR' => 'not-an-address'], []));
    }

    #[Test]
    public function an_unreadable_header_cannot_make_a_trusted_proxy_lose_the_visitor(): void
    {
        $server = [
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => 'somebody-elses-text',
        ];

        self::assertSame('198.51.100.7', ClientAddress::resolve($server, ['198.51.100.0/24']));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function networks(): iterable
    {
        // The first two are Cloudflare's own published ranges, written the way
        // the deployment record tells an operator to paste them.
        yield 'cloudflare v4, inside'      => ['173.245.48.0/20', '173.245.50.5', true];
        yield 'cloudflare v4, outside'     => ['173.245.48.0/20', '173.245.64.1', false];
        yield 'cloudflare v6, inside'      => ['2400:cb00::/32', '2400:cb00:1234::1', true];
        yield 'cloudflare v6, outside'     => ['2400:cb00::/32', '2606:4700::1', false];
        yield 'a single address'           => ['198.51.100.7', '198.51.100.7', true];
        yield 'a single address, off by one' => ['198.51.100.7', '198.51.100.8', false];
        yield 'a bite out of a byte'       => ['198.51.100.0/25', '198.51.100.100', true];
        yield 'the other bite'             => ['198.51.100.128/25', '198.51.100.100', false];
        yield 'the whole of v4'            => ['0.0.0.0/0', '203.0.113.9', true];
        yield 'families never mix'         => ['198.51.100.0/24', '2001:db8::1', false];
        yield 'an unreadable network'      => ['not-a-network', '203.0.113.9', false];
        yield 'an unreadable prefix'       => ['198.51.100.0/999', '198.51.100.7', false];
        yield 'an empty network'           => ['', '203.0.113.9', false];
    }

    #[Test]
    #[DataProvider('networks')]
    public function networks_are_matched_by_address_and_prefix(string $network, string $address, bool $expected): void
    {
        self::assertSame($expected, ClientAddress::contains($network, $address));
    }
}
