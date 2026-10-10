<?php

declare(strict_types=1);

namespace Bulbula\Backend\Database;

use Bulbula\Backend\Core\Config;
use PDO;
use Throwable;

/**
 * A lazily-opened database connection.
 *
 * Repositories depend on this, never on PDO directly, so a connection is
 * opened only when a query actually needs one — which is what lets the
 * readiness probe report a database outage instead of dying with it.
 */
final class Connection
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= ConnectionFactory::create($this->config);
    }

    /** `mysql` or `sqlite`; the two engines the project runs on. */
    public function driver(): string
    {
        $driver = $this->config->string('database.driver', 'mysql');

        return strtolower($driver);
    }

    /**
     * Whether a table exists.
     *
     * Here rather than in a caller because it is the one query that cannot be
     * written portably: MariaDB answers through `information_schema`, SQLite
     * through `sqlite_master`.
     */
    public function hasTable(string $table): bool
    {
        $statement = $this->driver() === 'sqlite'
            ? $this->pdo()->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name")
            : $this->pdo()->prepare(
                'SELECT table_name FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name = :name',
            );

        $statement->execute(['name' => $table]);

        return $statement->fetchColumn() !== false;
    }

    /** True when the database answers. Never throws. */
    public function ping(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
