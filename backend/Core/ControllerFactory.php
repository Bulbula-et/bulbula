<?php

declare(strict_types=1);

namespace Bulbula\Backend\Core;

use Bulbula\Backend\Exceptions\ConfigurationException;
use Closure;

/**
 * The explicit controller factories, in one table.
 *
 * A route names a controller class; this is what turns that name into an
 * object. It is deliberately not a container: there is no auto-wiring, no
 * reflection and no `get()` from application code — the table is written out
 * by hand in `Application::boot()`, so the wiring of every entry point can be
 * read in one place, and a controller that nothing wired fails loudly instead
 * of being conjured.
 *
 * @phpstan-type FactoryTable array<string, Closure(): object>
 */
final class ControllerFactory
{
    /** @var array<string, Closure(): object> */
    private array $factories;

    /** @param array<string, Closure(): object> $factories */
    public function __construct(array $factories)
    {
        $this->factories = $factories;
    }

    /** @throws ConfigurationException when the class was never wired */
    public function make(string $class): object
    {
        if (!isset($this->factories[$class])) {
            throw new ConfigurationException(
                'No controller is wired for [' . $class . ']. Add it to Application::boot().',
            );
        }

        return ($this->factories[$class])();
    }

    public function knows(string $class): bool
    {
        return isset($this->factories[$class]);
    }
}
