<?php

declare(strict_types=1);

namespace Bulbula\Backend\Console;

use Closure;

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

        return ($this->factories[$name])()->run(array_values(array_slice($argv, 2)));
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
