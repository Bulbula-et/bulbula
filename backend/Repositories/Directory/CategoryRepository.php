<?php

declare(strict_types=1);

namespace Bulbula\Backend\Repositories\Directory;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\DatabaseTime;
use Bulbula\Backend\Database\Row;
use Bulbula\Backend\Domain\Directory\Category;
use Bulbula\Backend\Domain\Directory\Slug;
use DateTimeImmutable;
use UnexpectedValueException;

/**
 * Every SQL statement that touches `categories` (TR-16).
 *
 * The catalogue is reference data (D-56): `create` exists for seeding and for
 * the operations surface, and nothing in application code depends on a
 * particular Category existing. Exactly two levels are enforced where they
 * can drift — `create` refuses to nest a Subcategory under a Subcategory —
 * because the alternative is a third level appearing by accident.
 */
final class CategoryRepository
{
    private const TABLE = 'categories';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?Category
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id',
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findBySlug(Slug $slug): ?Category
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE slug = :slug ORDER BY id ASC LIMIT 1',
        );
        $statement->execute(['slug' => $slug->value]);

        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @throws UnexpectedValueException when the parent would create a third level (D-06) */
    public function create(
        ?int $parentId,
        Slug $slug,
        string $name,
        ?string $nameAm,
        DateTimeImmutable $now,
        int $displayOrder = 0,
        bool $visible = true,
        ?string $icon = null,
    ): Category {
        if ($parentId !== null) {
            $parent = $this->findById($parentId);

            if ($parent === null) {
                throw new UnexpectedValueException('A Subcategory needs an existing parent Category.');
            }

            if (!$parent->isTopLevel()) {
                throw new UnexpectedValueException('The taxonomy is exactly two levels (D-06).');
            }
        }

        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO ' . self::TABLE
            . ' (parent_id, slug, name, name_am, display_order, visible, icon, created_at, updated_at)'
            . ' VALUES (:parent, :slug, :name, :name_am, :order, :visible, :icon, :now, :now)',
        );

        $statement->execute([
            'parent'   => $parentId,
            'slug'     => $slug->value,
            'name'     => $name,
            'name_am'  => $nameAm,
            'order'    => $displayOrder,
            'visible'  => $visible ? 1 : 0,
            'icon'     => $icon,
            'now'      => DatabaseTime::format($now),
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return $this->findById($id)
            ?? throw new UnexpectedValueException('The Category could not be read back after creation.');
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): Category
    {
        $parent = $row['parent_id'] ?? null;

        return new Category(
            id: Row::int($row, 'id'),
            parentId: is_int($parent) || is_string($parent) ? (int) $parent : null,
            slug: Slug::fromString(Row::string($row, 'slug')),
            name: Row::string($row, 'name'),
            nameAm: Row::nullableString($row, 'name_am'),
            displayOrder: Row::int($row, 'display_order'),
            visible: Row::int($row, 'visible') === 1,
        );
    }
}
