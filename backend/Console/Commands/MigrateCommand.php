<?php

declare(strict_types=1);

namespace Bulbula\Backend\Console\Commands;

use Bulbula\Backend\Console\Command;
use Bulbula\Backend\Console\Output;
use Bulbula\Backend\Database\Migrations\Migrator;
use Bulbula\Backend\Exceptions\MigrationException;

/**
 * `php bin/console migrate` — apply everything pending, as one batch.
 *
 * Migrations are run by hand on the host, after a backup (MG-7), never inside
 * a deploy script: a schema change is the moment a mistake becomes expensive,
 * and it is not a moment to automate away the decision to proceed.
 */
final class MigrateCommand implements Command
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly Output $output,
    ) {
    }

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Apply every pending migration, as one batch';
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $applied = $this->migrator->apply();
        } catch (MigrationException $error) {
            $this->output->error($error->getMessage());

            return 1;
        }

        if ($applied === []) {
            $this->output->line('Nothing to migrate: the schema is up to date.');

            return 0;
        }

        foreach ($applied as $name) {
            $this->output->line('applied  ' . $name);
        }

        $this->output->line(count($applied) . ' migration(s) applied.');

        return 0;
    }
}
