<?php

declare(strict_types=1);

namespace Bulbula\Tests\Feature\Web;

use Bulbula\Tests\Support\ApplicationTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A surface that is not built yet is absent, not broken (`architecture.md` §6).
 *
 * `/admin` was registered before its controller existed, so the URL answered
 * `500 Something went wrong` on the live host — the owner opened it and read an
 * outage where there was only a missing feature. The console's own prefix is
 * `/ops/*` (`architecture.md` §6.1) and it arrives with step 6 of the build
 * order, so until then these paths answer `404`, which is the honest answer to
 * "is this page here?".
 *
 * This test is replaced by the console slice's own tests when that slice lands.
 */
final class UnbuiltRoutesTest extends ApplicationTestCase
{
    #[Test]
    public function the_operations_console_is_absent_until_its_slice_lands(): void
    {
        foreach (['/ops', '/admin'] as $path) {
            $response = $this->get($path);

            self::assertSame(404, $response->status(), $path);
        }
    }
}
