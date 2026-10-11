<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Core;

use Bulbula\Backend\Core\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one place the outside world is read.
 *
 * `fromGlobals()` is the seam between the web server and everything else, so
 * what it believes matters — and the client address is the half of it the
 * deployment can get wrong (ClientAddress, deployment §5.4).
 */
final class RequestTest extends TestCase
{
    /** @var array<array-key, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    #[Test]
    public function the_peer_address_is_the_client_when_no_proxy_is_trusted(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1';

        // The default is the safe one: a header on its own is a claim, not a fact.
        self::assertSame('203.0.113.9', Request::fromGlobals()->clientIp);
    }

    #[Test]
    public function trusted_networks_reach_the_resolution(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';

        self::assertSame('203.0.113.9', Request::fromGlobals(['198.51.100.0/24'])->clientIp);
    }
}
