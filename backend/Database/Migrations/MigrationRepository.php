<?php

declare(strict_types=1);

namespace Bulbula\Backend\Database\Migrations;

use Bulbula\Backend\Database\Connection;
use DateTimeImmutable;

/**
 * The tracking table: which migrations have run, and in which batch.
 *
 * Batches are what make rollback meaningful — "undo the last deploy's schema
 * changes" is a question a batch can answer and a timestamp cannot. The table
 * is created by the migrator rather than by a migration, because a migration
 * cannot be applied before something knows what has been applied.
 */
final class MigrationRepository
{
    public const TABLE = 'migrations';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function exists(): bool
    {
        return $this->connection->hasTable(self::TABLE);
    }

    public function create(): void
    {
        // One definition both engines accept unchanged: MariaDB and the
        // suite's SQLite share this DDL as written.
        $this->connection->pdo()->exec(
            'CREATE TABLE ' . self::TABLE . ' ('
            . 'migration VARCHAR(190) NOT NULL, '
            . 'batch INTEGER NOT NULL, '
            . 'executed_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (migration)'
            . ')',
        );
    }

    /** @return array<string, int> migration name => batch, oldest applied first */
    public function applied(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $statement = $this->connection->pdo()->prepare(
            'SELECT migration, batch FROM ' . self::TABLE . ' ORDER BY batch, migration',
        );
        $statement->execute();

        /** @var array<string, int> $applied */
        $applied = $statement->fetchAll(\PDO::FETCH_KEY_PAIR);

        return $applied;
    }

    public function lastBatch(): int
    {
        $batches = array_values($this->applied());

        return $batches === [] ? 0 : max($batches);
    }

    public function record(string $migration, int $batch, DateTimeImmutable $executedAt): void
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO ' . self::TABLE . ' (migration, batch, executed_at) '
            . 'VALUES (:migration, :batch, :executed_at)',
        );

        $statement->execute([
            'migration'   => $migration,
            'batch'       => $batch,
            'executed_at' => $executedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function forget(string $migration): void
    {
        $statement = $this->connection->pdo()->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE migration = :migration',
        );

        $statement->execute(['migration' => $migration]);
    }
}
