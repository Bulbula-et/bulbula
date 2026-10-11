<?php

declare(strict_types=1);

namespace Bulbula\Backend\Database\Migrations;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Exceptions\MigrationException;
use Bulbula\Backend\Support\Clock;
use Throwable;

/**
 * Applies, reverts and reports migrations.
 *
 * Order is the file name, and the file name is a timestamp: the order the
 * migrations were written is the order the schema grew, and that order is not
 * negotiable after the fact. A pending migration that sorts *before* one
 * already applied is refused rather than applied — that is a branch or a deploy
 * that went backwards, and applying it would rewrite history out of order.
 */
final class Migrator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MigrationLocator $locator,
        private readonly MigrationRepository $repository,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<string> every migration file, oldest first */
    public function files(): array
    {
        return $this->locator->names();
    }

    /** @return array<string, int> applied migration => batch */
    public function applied(): array
    {
        return $this->repository->applied();
    }

    /** @return list<string> files that have not been applied */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter(
            $this->files(),
            static fn (string $name): bool => !array_key_exists($name, $applied),
        ));
    }

    /**
     * Pending migrations older than the newest applied one.
     *
     * @return list<string>
     */
    public function outOfOrder(): array
    {
        $applied = $this->applied();

        if ($applied === []) {
            return [];
        }

        $names = array_keys($applied);
        sort($names);
        $newest = (string) end($names);

        return array_values(array_filter(
            $this->pending(),
            static fn (string $name): bool => strcmp($name, $newest) < 0,
        ));
    }

    /**
     * Apply every pending migration, as one batch.
     *
     * @return list<string> what this call applied
     *
     * @throws MigrationException when the history is inconsistent or a migration fails
     */
    public function apply(): array
    {
        $outOfOrder = $this->outOfOrder();

        if ($outOfOrder !== []) {
            throw new MigrationException(
                'Refusing to apply migrations that sort before an applied one: ' . implode(', ', $outOfOrder),
            );
        }

        if (!$this->repository->exists()) {
            $this->repository->create();
        }

        $pending = $this->pending();

        if ($pending === []) {
            return [];
        }

        $batch = $this->repository->lastBatch() + 1;
        $applied = [];

        foreach ($pending as $name) {
            $this->run($name, function (Migration $migration): void {
                $migration->up($this->connection);
            });

            $this->repository->record($name, $batch, $this->clock->now());

            $applied[] = $name;
        }

        return $applied;
    }

    /**
     * Revert the last `$steps` batches, newest migration first.
     *
     * Only the last batches are ever revertible: an older batch sits under
     * everything applied since, and reversing it would leave a schema that no
     * migration describes.
     *
     * @return list<string> what this call reverted
     *
     * @throws MigrationException
     */
    public function rollback(int $steps = 1): array
    {
        if ($steps < 1) {
            throw new MigrationException('Rollback steps must be at least 1.');
        }

        $applied = $this->applied();

        if ($applied === []) {
            return [];

        }

        $batches = array_values(array_unique($applied));
        rsort($batches);
        $batches = array_slice($batches, 0, $steps);

        $reverted = [];

        foreach ($batches as $batch) {
            $inBatch = array_keys(array_filter($applied, static fn (int $b): bool => $b === $batch));
            rsort($inBatch);

            foreach ($inBatch as $name) {
                $this->run($name, function (Migration $migration): void {
                    $migration->down($this->connection);
                });

                $this->repository->forget($name);

                $reverted[] = $name;
            }
        }

        return $reverted;
    }

    /**
     * Every migration with its state, in application order.
     *
     * @return list<array{name: string, state: string, batch: int|null}>
     */
    public function status(): array
    {
        $applied = $this->applied();

        return array_map(
            static fn (string $name): array => [
                'name'  => $name,
                'state' => array_key_exists($name, $applied) ? 'applied' : 'pending',
                'batch' => $applied[$name] ?? null,
            ],
            $this->files(),
        );
    }

    /**
     * A migration is recorded only after it succeeds, and is expected to be
     * safe to re-run from the point where it stopped: MariaDB commits DDL
     * implicitly, so wrapping it in a transaction would hide the failure rather
     * than make the change atomic.
     *
     * @param callable(Migration): void $operation
     *
     * @throws MigrationException
     */
    private function run(string $name, callable $operation): void
    {
        try {
            $operation($this->locator->load($name));
        } catch (Throwable $error) {
            throw new MigrationException('Migration [' . $name . '] failed: ' . $error->getMessage(), 0, $error);
        }
    }
}
