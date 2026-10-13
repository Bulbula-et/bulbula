<?php

declare(strict_types=1);

namespace Bulbula\Backend\Repositories\Directory;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\DatabaseTime;
use Bulbula\Backend\Database\Row;
use Bulbula\Backend\Domain\Directory\Area;
use Bulbula\Backend\Domain\Directory\Slug;
use DateTimeImmutable;
use UnexpectedValueException;

/** Every SQL statement that touches `areas` (TR-16). */
final class AreaRepository
{
    private const TABLE = 'areas';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?Area
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id',
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findBySlug(Slug $slug): ?Area
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE slug = :slug',
        );
        $statement->execute(['slug' => $slug->value]);

        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Every Area, in display order — small reference data (D-56), read
     * wholesale for the browse indexes and the search facets.
     *
     * @return list<Area>
     */
    public function all(): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY display_order ASC, name ASC, id ASC',
        );
        $statement->execute();

        $areas = [];

        while (($row = $statement->fetch()) !== false) {
            if (is_array($row)) {
                $areas[] = $this->hydrate($row);
            }
        }

        return $areas;
    }

    /**
     * The Areas with those ids, keyed by id — one query for a whole page's
     * worth of rows rather than one query per row (DB-10).
     *
     * @param list<int> $ids
     *
     * @return array<int, Area>
     */
    public function byIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')',
        );
        $statement->execute($ids);

        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $hydrated          = $this->hydrate($row);
                $rows[$hydrated->id] = $hydrated;
            }
        }

        return $rows;
    }

    public function create(
        int $subCityId,
        Slug $slug,
        string $name,
        ?string $nameAm,
        DateTimeImmutable $now,
        int $displayOrder = 0,
        bool $visible = true,
    ): Area {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO ' . self::TABLE
            . ' (sub_city_id, slug, name, name_am, display_order, visible, created_at, updated_at)'
            . ' VALUES (:sub_city, :slug, :name, :name_am, :order, :visible, :created_at, :updated_at)',
        );

        // One stamp, two slots: MySQL binds a name once per statement, so the
        // two timestamps are separate parameters however equal their values
        // (see the note on PDO_MySQL in ConnectionFactory).
        $stamp = DatabaseTime::format($now);

        $statement->execute([
            'sub_city'   => $subCityId,
            'slug'       => $slug->value,
            'name'       => $name,
            'name_am'    => $nameAm,
            'order'      => $displayOrder,
            'visible'    => $visible ? 1 : 0,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return $this->findById($id)
            ?? throw new UnexpectedValueException('The Area could not be read back after creation.');
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): Area
    {
        return new Area(
            id: Row::int($row, 'id'),
            subCityId: Row::int($row, 'sub_city_id'),
            slug: Slug::fromString(Row::string($row, 'slug')),
            name: Row::string($row, 'name'),
            nameAm: Row::nullableString($row, 'name_am'),
            displayOrder: Row::int($row, 'display_order'),
            visible: Row::int($row, 'visible') === 1,
        );
    }
}
