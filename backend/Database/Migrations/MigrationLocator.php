<?php

declare(strict_types=1);

namespace Bulbula\Backend\Database\Migrations;

use Bulbula\Backend\Exceptions\MigrationException;

/**
 * Finds migration files and puts them in the only order that means anything:
 * the order their names sort in.
 *
 * Names are timestamps (`YYYY_MM_DD_HHMMSS_description.php`), so sorting is
 * ordering, and a mistake in the name is a mistake in the history — which is
 * why an unexpected name is refused rather than accepted quietly.
 */
final class MigrationLocator
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @return list<string> file names, oldest first */
    public function names(): array
    {
        $paths = glob(rtrim($this->directory, '/') . '/*.php') ?: [];
        $names = array_map(basename(...), $paths);
        sort($names);

        return $names;
    }

    public function path(string $name): string
    {
        return rtrim($this->directory, '/') . '/' . $name;
    }

    /** @throws MigrationException when a file is not named like a migration */
    public function load(string $name): Migration
    {
        if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/', $name) !== 1) {
            throw new MigrationException(
                'Migration [' . $name . '] must be named YYYY_MM_DD_HHMMSS_description.php.',
            );
        }

        $path = $this->path($name);

        if (!is_file($path)) {
            throw new MigrationException('Migration [' . $name . '] does not exist.');
        }

        $migration = require $path;

        if (!$migration instanceof Migration) {
            throw new MigrationException('Migration [' . $name . '] must return a ' . Migration::class . '.');
        }

        return $migration;
    }
}
