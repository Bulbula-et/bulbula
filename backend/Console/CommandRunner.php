<?php

declare(strict_types=1);

namespace Bulbula\Backend\Console;

use Closure;
use Throwable;

/**
 * Turns `argv` into one command run.
 *
 * The table of factories is written by hand in the composition root, exactly
 * like the controller table: a command that is not listed cannot be reached,
 * and a name nobody wired fails loudly instead of being conjured (R-3).
 */
final class CommandRunner
{
    /** @param array<string, Closure(): Command> $factories */
    public function __construct(
        private readonly array $factories,
        private readonly Output $output,
    ) {
    }

    /** @return list<string> the commands this deployment offers, in declaration order */
    public function names(): array
    {
        return array_keys($this->factories);
    }

    /** @param list<string> $argv the full argument vector, including the script name */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? 'list';

        if ($name === 'list' || $name === '--help' || $name === '-h') {
            $this->list();

            return 0;
        }

        if (!isset($this->factories[$name])) {
            $this->output->error('Unknown command [' . $name . '].');

            return 1;
        }

        try {
            return ($this->factories[$name])()->run(array_values(array_slice($argv, 2)));
        } catch (Throwable $error) {
            // A command that fails must say so on the terminal. Production sets
            // display_errors off, so an exception reaching the top of the script
            // prints nothing on some hosts — and a console that prints nothing
            // is indistinguishable from a console that succeeded, which is how
            // an operator concludes "there was nothing to do" while the schema
            // they believe in does not exist.
            $this->output->error(sprintf(
                'Command [%s] failed: %s: %s',
                $name,
                $error::class,
                $error->getMessage(),
            ));

            return 1;
        }
    }

    private function list(): void
    {
        $this->output->line('Bulbula console');
        $this->output->line();

        foreach ($this->names() as $name) {
            $this->output->line(sprintf('  %-18s %s', $name, ($this->factories[$name])()->description()));
        }

        $this->output->line();
        $this->output->line('Run with --help after a command name for its options.');
    }
}
