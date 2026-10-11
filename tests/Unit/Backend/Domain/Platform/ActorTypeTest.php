<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Domain\Platform;

use Bulbula\Backend\Domain\Platform\ActorType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The actor vocabulary, and the one rule the rest of the audit trail leans on:
 * an authenticated actor must name itself, an unauthenticated one must not.
 */
final class ActorTypeTest extends TestCase
{
    #[Test]
    public function it_knows_exactly_five_actors(): void
    {
        self::assertSame(
            ['guest', 'customer', 'operator', 'administrator', 'system'],
            array_map(static fn (ActorType $actor): string => $actor->value, ActorType::cases()),
        );
    }

    #[Test]
    public function it_never_invents_a_business_actor(): void
    {
        // No Business principal exists (D-54, TR-40): there is nobody for such
        // an entry to describe, so there is no case for one.
        self::assertSame([], array_filter(
            ActorType::cases(),
            static fn (ActorType $actor): bool => str_contains($actor->value, 'business'),
        ));
    }

    #[Test]
    public function only_an_account_holder_is_authenticated(): void
    {
        $authenticated = array_map(
            static fn (ActorType $actor): string => $actor->value,
            array_values(array_filter(
                ActorType::cases(),
                static fn (ActorType $actor): bool => $actor->isAuthenticated(),
            )),
        );

        self::assertSame(['customer', 'operator', 'administrator'], $authenticated);
    }

    #[Test]
    public function a_guest_and_the_platform_are_not_accounts(): void
    {
        self::assertFalse(ActorType::Guest->isAuthenticated());
        self::assertFalse(ActorType::System->isAuthenticated());
    }
}
