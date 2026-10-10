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
