<?php

declare(strict_types=1);

namespace Bulbula\Backend\Repositories\Platform;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\DatabaseTime;
use DateTimeImmutable;

/**
 * Every SQL statement that touches `redirects` (TR-16, data-model §8.7).
 *
 * A redirect exists so that a link a person shared keeps working after a slug
 * change (IAR-3, SEO-4). `old_path` is unique, so a second claim on the same
 * address fails loudly instead of overwriting the first.
 */
final class RedirectRepository
{
    private const TABLE = 'redirects';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(string $oldPath, string $newPath, string $reason, DateTimeImmutable $now): void
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO ' . self::TABLE . ' (old_path, new_path, reason, created_at)'
            . ' VALUES (:old, :new, :reason, :now)',
        );

        $statement->execute([
            'old'    => $oldPath,
            'new'    => $newPath,
            'reason' => $reason,
            'now'    => DatabaseTime::format($now),
        ]);
    }

    public function find(string $oldPath): ?string
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT new_path FROM ' . self::TABLE . ' WHERE old_path = :old',
        );
        $statement->execute(['old' => $oldPath]);

        $target = $statement->fetchColumn();

        return is_string($target) ? $target : null;
    }
}
