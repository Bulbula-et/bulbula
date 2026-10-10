<?php

declare(strict_types=1);

namespace Bulbula\Backend\Console\Commands;

use Bulbula\Backend\Console\Command;
use Bulbula\Backend\Console\Output;
use Bulbula\Backend\Database\Migrations\Migrator;
use Bulbula\Backend\Exceptions\MigrationException;

/**
 * `php bin/console rollback [--steps=n]` — revert the last batch, or the last n.
 *
 * This is the escape hatch for a deploy that must be undone, not a routine
 * step: reverting a migration that has since been applied over — a batch that
 * is no longer the newest — is refused by the migrator rather than attempted.
 */
final class RollbackCommand implements Command
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly Output $output,
    ) {
    }

    public function name(): string
    {
        return 'rollback';
    }

    public function description(): string
    {
        return 'Revert the last batch, or the last --steps=n batches';
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $steps = $this->steps($arguments);
            $reverted = $this->migrator->rollback($steps);
        } catch (MigrationException $error) {
            $this->output->error($error->getMessage());

            return 1;
        }

        if ($reverted === []) {
            $this->output->line('Nothing to roll back.');

            return 0;
        }

        foreach ($reverted as $name) {
            $this->output->line('reverted ' . $name);
        }

        $this->output->line(count($reverted) . ' migration(s) reverted.');

        return 0;
    }

    /**
     * @param list<string> $arguments
     *
     * @throws MigrationException when the option is not a positive integer
     */
    private function steps(array $arguments): int
    {
        foreach ($arguments as $argument) {
            if (preg_match('/^--steps=(\d+)$/', $argument, $matches) !== 1) {
                continue;
            }

            $steps = (int) $matches[1];

            if ($steps < 1) {
                throw new MigrationException('--steps must be at least 1.');
            }

            return $steps;
        }

        return 1;
    }
}
