<?php

declare(strict_types=1);

namespace Bulbula\Backend\Repositories\Directory;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\DatabaseTime;
use Bulbula\Backend\Database\Row;
use Bulbula\Backend\Domain\Directory\Slug;
use Bulbula\Backend\Domain\Directory\SubCity;
use DateTimeImmutable;
use UnexpectedValueException;

/** Every SQL statement that touches `sub_cities` (TR-16). */
final class SubCityRepository
{
    private const TABLE = 'sub_cities';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?SubCity
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id',
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findBySlug(Slug $slug): ?SubCity
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE slug = :slug ORDER BY id ASC LIMIT 1',
        );
        $statement->execute(['slug' => $slug->value]);

        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * The SubCitys with those ids, keyed by id — one query for a whole page's
     * worth of rows rather than one query per row (DB-10).
     *
     * @param list<int> $ids
     *
     * @return array<int, SubCity>
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

    public function create(Slug $slug, string $name, ?string $nameAm, string $city, DateTimeImmutable $now): SubCity
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO ' . self::TABLE . ' (slug, name, name_am, city, created_at, updated_at)'
            . ' VALUES (:slug, :name, :name_am, :city, :created_at, :updated_at)',
        );

        // One stamp, two slots: PDO_MySQL binds a name once per statement.
        $stamp = DatabaseTime::format($now);

        $statement->execute([
            'slug'       => $slug->value,
            'name'       => $name,
            'name_am'    => $nameAm,
            'city'       => $city,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return $this->findById($id)
            ?? throw new UnexpectedValueException('The Sub-city could not be read back after creation.');
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): SubCity
    {
        return new SubCity(
            id: Row::int($row, 'id'),
            slug: Slug::fromString(Row::string($row, 'slug')),
            name: Row::string($row, 'name'),
            nameAm: Row::nullableString($row, 'name_am'),
            city: Row::string($row, 'city'),
        );
    }
}
