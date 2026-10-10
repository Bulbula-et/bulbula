<?php

declare(strict_types=1);

namespace Bulbula\Backend\Console;

/**
 * One console command.
 *
 * A command returns an exit code instead of calling `exit()`, so the behaviour
 * a deployment depends on is testable: `bin/console` is the only file in the
 * project permitted to end the process (CR-6, architecture assertion).
 */
interface Command
{
    /** The name a person types, e.g. `migrate` or `migration:status`. */
    public function name(): string;

    /** One line, for the command list. */
    public function description(): string;

    /**
     * @param list<string> $arguments everything after the command name
     *
     * @return int the process exit code: 0 on success
     */
    public function run(array $arguments): int;
}
