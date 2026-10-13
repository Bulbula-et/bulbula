<?php

declare(strict_types=1);

namespace Bulbula\Backend\Repositories\Directory;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\Row;
use Bulbula\Backend\Domain\Directory\Alias;

/**
 * Every SQL statement that touches `aliases` (TR-16).
 *
 * Aliases are reference data (D-56): staff add them through taxonomy and
 * location management (AL-2) or a reference import, and a new Alias is a data
 * operation with no deployment (AL-5). `alias_normalised` is stored beside
 * the display text so the uniqueness rule — one Alias per target per wording —
 * is a database constraint and not a hope.
 */
final class AliasRepository
{
    private const TABLE = 'aliases';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Every Alias, target type first — the whole table is small reference
     * data, and matching reads it wholesale into the search document.
     *
     * @return list<Alias>
     */
    public function all(): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY target_type ASC, target_id ASC, id ASC',
        );
        $statement->execute();

        $aliases = [];

        while (($row = $statement->fetch()) !== false) {
            if (is_array($row)) {
                $aliases[] = $this->hydrate($row);
            }
        }

        return $aliases;
    }

    /**
     * @return list<Alias>
     */
    public function forTarget(string $targetType, int $targetId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE
            . ' WHERE target_type = :target_type AND target_id = :target_id'
            . ' ORDER BY id ASC',
        );
        $statement->execute(['target_type' => $targetType, 'target_id' => $targetId]);

        $aliases = [];

        while (($row = $statement->fetch()) !== false) {
            if (is_array($row)) {
                $aliases[] = $this->hydrate($row);
            }
        }

        return $aliases;
    }

    /**
     * Record one Alias, ignoring a wording the target already carries — the
     * unique index is the rule, so a re-import is safe to re-run. Returns
     * whether the row was new.
     */
    public function add(
        string $targetType,
        int $targetId,
        string $aliasText,
        string $aliasNormalised,
        ?string $language,
        string $createdAt,
    ): bool {
        $existing = $this->connection->pdo()->prepare(
            'SELECT id FROM ' . self::TABLE
            . ' WHERE target_type = :check_target_type AND target_id = :check_target_id'
            . ' AND alias_normalised = :check_alias_normalised',
        );
        $existing->execute([
            'check_target_type'      => $targetType,
            'check_target_id'        => $targetId,
            'check_alias_normalised' => $aliasNormalised,
        ]);

        if ($existing->fetch() !== false) {
            return false;
        }

        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO ' . self::TABLE
            . ' (target_type, target_id, alias_text, alias_normalised, language, created_at)'
            . ' VALUES (:target_type, :target_id, :alias_text, :alias_normalised, :language, :created_at)',
        );
        $statement->execute([
            'target_type'      => $targetType,
            'target_id'        => $targetId,
            'alias_text'       => $aliasText,
            'alias_normalised' => $aliasNormalised,
            'language'         => $language,
            'created_at'       => $createdAt,
        ]);

        return true;
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): Alias
    {
        return new Alias(
            Row::int($row, 'id'),
            Row::string($row, 'target_type'),
            Row::int($row, 'target_id'),
            Row::string($row, 'alias_text'),
            Row::string($row, 'alias_normalised'),
            Row::nullableString($row, 'language'),
        );
    }
}
