<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Core;

use Bulbula\Backend\Core\ControllerFactory;
use Bulbula\Backend\Exceptions\ConfigurationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The wiring table is the whole of the controller construction: no reflection,
 * no auto-wiring, and no way to reach a class nobody wired.
 */
final class ControllerFactoryTest extends TestCase
{
    #[Test]
    public function it_builds_the_object_the_table_names(): void
    {
        $factory = new ControllerFactory([
            ControllerFactoryTestController::class => static fn (): ControllerFactoryTestController
                => new ControllerFactoryTestController('wired'),
        ]);

        $controller = $factory->make(ControllerFactoryTestController::class);

        self::assertInstanceOf(ControllerFactoryTestController::class, $controller);
        self::assertSame('wired', $controller->dependency);
    }

    #[Test]
    public function it_builds_a_fresh_instance_for_each_call(): void
    {
        // Two routes may name the same controller; neither should be able to
        // observe state left by the other.
        $factory = new ControllerFactory([
            ControllerFactoryTestController::class => static fn (): ControllerFactoryTestController
                => new ControllerFactoryTestController('wired'),
        ]);

        self::assertNotSame(
            $factory->make(ControllerFactoryTestController::class),
            $factory->make(ControllerFactoryTestController::class),
        );
    }

    #[Test]
    public function an_unwired_class_fails_loudly(): void
    {
        $factory = new ControllerFactory([]);

        self::assertFalse($factory->knows(ControllerFactoryTestController::class));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No controller is wired for');

        $factory->make(ControllerFactoryTestController::class);
    }
}

/**
 * A stand-in controller: the point of the test is the wiring, not the class.
 */
final class ControllerFactoryTestController
{
    public function __construct(public readonly string $dependency)
    {
    }
}
