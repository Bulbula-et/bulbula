<?php

declare(strict_types=1);

namespace Bulbula\Backend\Console\Commands;

use Bulbula\Backend\Console\Command;
use Bulbula\Backend\Console\Output;
use Bulbula\Backend\Database\Migrations\Migrator;
use Bulbula\Backend\Exceptions\MigrationException;

/**
 * `php bin/console migration:status` — what has run, what has not.
 *
 * The first thing to type when a deploy behaves like a schema that is not the
 * schema you think: this answers it from the database, not from a changelog.
 */
final class MigrationStatusCommand implements Command
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly Output $output,
    ) {
    }

    public function name(): string
    {
        return 'migration:status';
    }

    public function description(): string
    {
        return 'List migrations: applied, with their batch, or pending';
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $status = $this->migrator->status();
            $outOfOrder = $this->migrator->outOfOrder();
        } catch (MigrationException $error) {
            $this->output->error($error->getMessage());

            return 1;
        }

        foreach ($status as $migration) {
            $this->output->line(sprintf(
                '%-8s %-22s %s',
                $migration['state'],
                $migration['batch'] === null ? '' : 'batch ' . $migration['batch'],
                $migration['name'],
            ));
        }

        if ($status === []) {
            $this->output->line('No migration files found.');
        }

        foreach ($outOfOrder as $name) {
            $this->output->error('out of order: ' . $name . ' sorts before an applied migration.');
        }

        return $outOfOrder === [] ? 0 : 1;
    }
}
