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

    public function create(Slug $slug, string $name, ?string $nameAm, string $city, DateTimeImmutable $now): SubCity
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO ' . self::TABLE . ' (slug, name, name_am, city, created_at, updated_at)'
            . ' VALUES (:slug, :name, :name_am, :city, :now, :now)',
        );

        $statement->execute([
            'slug'    => $slug->value,
            'name'    => $name,
            'name_am' => $nameAm,
            'city'    => $city,
            'now'     => DatabaseTime::format($now),
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
