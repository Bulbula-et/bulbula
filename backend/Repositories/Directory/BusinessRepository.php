<?php

declare(strict_types=1);

namespace Bulbula\Backend\Repositories\Directory;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\DatabaseTime;
use Bulbula\Backend\Database\Row;
use Bulbula\Backend\Domain\Directory\Business;
use Bulbula\Backend\Domain\Directory\BusinessState;
use Bulbula\Backend\Domain\Directory\Slug;
use DateTimeImmutable;
use UnexpectedValueException;

/**
 * Every SQL statement that reads or writes `businesses` and its
 * `business_categories` classification (TR-16: this repository is the only
 * code that touches the Business tables).
 *
 * Three properties are structural, not stylistic:
 *
 * - **`version` guards every mutation.** Each update asserts the version it
 *   read and increments it. `UPDATE … WHERE id = ? AND version = ?` always
 *   changes the row when it matches — the increment guarantees it — so a
 *   `rowCount()` of 1 means "the edit applied", 0 means "someone else edited
 *   first" on both SQLite and MySQL, whose "rows changed" accounting
 *   otherwise differs (TR-57).
 * - **The slug is unique and is never rewritten in place by this class.** A
 *   slug change goes through `changeSlug`, which also records the redirect
 *   (IAR-3); there is no path that quietly renames a URL.
 * - **Duplicate candidates are found by exact, normalised comparison** — the
 *   same name, the same public address, the same branch phone. That is the
 *   deterministic signal TR-55 asks to surface; fuzzy matching would be a
 *   different feature with a different decision behind it.
 */
final class BusinessRepository
{
    private const TABLE = 'businesses';

    private const CLASSIFICATION = 'business_categories';

    /** The profile columns an operator may edit, and nothing else. */
    private const UPDATABLE = [
        'name', 'legal_name', 'name_am', 'short_description',
        'long_description', 'website', 'email', 'social_links',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?Business
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id',
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findBySlug(Slug $slug): ?Business
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE slug = :slug',
        );
        $statement->execute(['slug' => $slug->value]);

        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function slugExists(Slug $slug): bool
    {
        return $this->findBySlug($slug) !== null;
    }

    public function create(
        Slug $slug,
        string $name,
        ?string $legalName,
        ?string $nameAm,
        DateTimeImmutable $now,
        ?int $actorId,
    ): Business {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO ' . self::TABLE
            . ' (slug, name, legal_name, name_am, status, version, created_by, created_at, updated_at)'
            . ' VALUES (:slug, :name, :legal_name, :name_am, :status, 1, :actor, :created_at, :updated_at)',
        );

        // One stamp, two slots: PDO_MySQL binds a name once per statement.
        $stamp = DatabaseTime::format($now);

        $statement->execute([
            'slug'       => $slug->value,
            'name'       => $name,
            'legal_name' => $legalName,
            'name_am'    => $nameAm,
            'status'     => BusinessState::Draft->value,
            'actor'      => $actorId,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return $this->findById($id)
            ?? throw new UnexpectedValueException('The Business could not be read back after creation.');
    }

    /**
     * Apply profile edits under the optimistic-concurrency token (TR-57).
     *
     * @param array<string, string|null> $changes column => value; unknown columns are ignored
     *
     * @return bool false when the version no longer matches — the caller reports the conflict
     */
    public function updateProfile(
        int $id,
        array $changes,
        int $expectedVersion,
        DateTimeImmutable $now,
        ?int $actorId,
    ): bool {
        $assignments = [];
        $parameters = ['id' => $id, 'version' => $expectedVersion, 'now' => DatabaseTime::format($now), 'actor' => $actorId];

        foreach (self::UPDATABLE as $column) {
            if (!array_key_exists($column, $changes)) {
                continue;
            }

            $assignments[] = $column . ' = :' . $column;
            $parameters[$column] = $changes[$column];
        }

        return $this->applyVersionedUpdate($id, $assignments, $parameters, $expectedVersion, $now, $actorId);
    }

    public function changeSlug(int $id, Slug $slug, int $expectedVersion, DateTimeImmutable $now, ?int $actorId): bool
    {
        return $this->applyVersionedUpdate(
            $id,
            ['slug = :slug'],
            ['slug' => $slug->value],
            $expectedVersion,
            $now,
            $actorId,
        );
    }

    public function markPublished(int $id, DateTimeImmutable $now, ?int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE ' . self::TABLE
            . ' SET status = :status, published_at = :published_at, version = version + 1,'
            . ' updated_at = :updated_at, updated_by = :actor'
            . ' WHERE id = :id',
        );

        $stamp = DatabaseTime::format($now);

        $statement->execute([
            'status'       => BusinessState::Published->value,
            'published_at' => $stamp,
            'updated_at'   => $stamp,
            'actor'        => $actorId,
            'id'           => $id,
        ]);
    }

    public function markUnpublished(int $id, DateTimeImmutable $now, ?int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE ' . self::TABLE
            . ' SET status = :status, unpublished_at = :unpublished_at, version = version + 1,'
            . ' updated_at = :updated_at, updated_by = :actor'
            . ' WHERE id = :id',
        );

        $stamp = DatabaseTime::format($now);

        $statement->execute([
            'status'         => BusinessState::Unpublished->value,
            'unpublished_at' => $stamp,
            'updated_at'     => $stamp,
            'actor'          => $actorId,
            'id'             => $id,
        ]);
    }

    /** Closure is recorded, never deleted (TR-53). */
    public function markClosed(int $id, string $reason, DateTimeImmutable $now, ?int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE ' . self::TABLE
            . ' SET status = :status, closed_at = :closed_at, closure_reason = :reason,'
            . ' version = version + 1, updated_at = :updated_at, updated_by = :actor'
            . ' WHERE id = :id',
        );

        $stamp = DatabaseTime::format($now);

        $statement->execute([
            'status'     => BusinessState::Closed->value,
            'closed_at'  => $stamp,
            'reason'     => $reason,
            'updated_at' => $stamp,
            'actor'      => $actorId,
            'id'         => $id,
        ]);
    }

    public function assignCategory(int $businessId, int $categoryId, bool $isPrimary, DateTimeImmutable $now): void
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO ' . self::CLASSIFICATION . ' (business_id, category_id, is_primary, created_at)'
            . ' VALUES (:business, :category, :primary, :now)',
        );

        $statement->execute([
            'business' => $businessId,
            'category' => $categoryId,
            'primary'  => $isPrimary ? 1 : 0,
            'now'      => DatabaseTime::format($now),
        ]);
    }

    public function clearPrimaryCategories(int $businessId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE ' . self::CLASSIFICATION . ' SET is_primary = 0 WHERE business_id = :business AND is_primary = 1',
        );

        $statement->execute(['business' => $businessId]);
    }

    public function hasCategory(int $businessId, int $categoryId): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . self::CLASSIFICATION
            . ' WHERE business_id = :business AND category_id = :category',
        );
        $statement->execute(['business' => $businessId, 'category' => $categoryId]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Bump the aggregate's version **without asserting it**: an additive change
     * (a new Branch, a new classification) cannot destroy anybody's typed
     * input, so it does not require the caller's token — but it must still move
     * the version, so a concurrent profile edit fails loudly instead of
     * landing unnoticed on top of it (TR-57).
     */
    public function touch(int $id, DateTimeImmutable $now, ?int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE ' . self::TABLE
            . ' SET version = version + 1, updated_at = :now, updated_by = :actor'
            . ' WHERE id = :id',
        );

        $statement->execute([
            'now'   => DatabaseTime::format($now),
            'actor' => $actorId,
            'id'    => $id,
        ]);
    }

    public function primaryCategoryCount(int $businessId): int
    {
        return $this->countClassification($businessId, 'AND is_primary = 1');
    }

    public function categoryCount(int $businessId): int
    {
        return $this->countClassification($businessId, '');
    }

    /**
     * The duplicate signal at creation time (TR-55), by exact normalised
     * comparison: the same name, the same public email, or a branch phone
     * number that is already on file. Area alone is not a signal — every
     * business in an area shares it.
     *
     * @return list<Business>
     */
    public function duplicateCandidates(string $name, ?string $email, ?string $phone): array
    {
        $conditions = ['LOWER(TRIM(b.name)) = LOWER(TRIM(:name))'];
        $parameters = ['name' => $name];

        if ($email !== null && $email !== '') {
            $conditions[] = 'b.email = :email';
            $parameters['email'] = $email;
        }

        if ($phone !== null && $phone !== '') {
            // Two slots, one value: PDO_MySQL binds a name once per statement.
            $conditions[] = '(br.phone = :phone OR br.phone_secondary = :phone_secondary)';
            $parameters['phone']           = $phone;
            $parameters['phone_secondary'] = $phone;
        }

        $statement = $this->connection->pdo()->prepare(
            'SELECT DISTINCT b.* FROM ' . self::TABLE . ' b'
            . ' LEFT JOIN branches br ON br.business_id = b.id'
            . ' WHERE ' . implode(' OR ', $conditions),
        );
        $statement->execute($parameters);

        $candidates = [];

        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $candidates[] = $this->hydrate($row);
            }
        }

        return $candidates;
    }

    /**
     * @param list<string>                $assignments additional `column = :placeholder` fragments
     * @param array<string, mixed>        $parameters
     */
    private function applyVersionedUpdate(
        int $id,
        array $assignments,
        array $parameters,
        int $expectedVersion,
        DateTimeImmutable $now,
        ?int $actorId,
    ): bool {
        $assignments[] = 'version = version + 1';
        $assignments[] = 'updated_at = :now';
        $assignments[] = 'updated_by = :actor';

        $parameters['id'] = $id;
        $parameters['version'] = $expectedVersion;
        $parameters['now'] = DatabaseTime::format($now);
        $parameters['actor'] = $actorId;

        $statement = $this->connection->pdo()->prepare(
            'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $assignments)
            . ' WHERE id = :id AND version = :version',
        );
        $statement->execute($parameters);

        return $statement->rowCount() === 1;
    }

    private function countClassification(int $businessId, string $extra): int
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . self::CLASSIFICATION . ' WHERE business_id = :business ' . $extra,
        );
        $statement->execute(['business' => $businessId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * A row becomes a Business here, in the storage layer, rather than inside
     * the domain object (STR-3, DB-10).
     *
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): Business
    {
        return new Business(
            id: Row::int($row, 'id'),
            slug: Slug::fromString(Row::string($row, 'slug')),
            name: Row::string($row, 'name'),
            legalName: Row::nullableString($row, 'legal_name'),
            nameAm: Row::nullableString($row, 'name_am'),
            shortDescription: Row::nullableString($row, 'short_description'),
            longDescription: Row::nullableString($row, 'long_description'),
            website: Row::nullableString($row, 'website'),
            email: Row::nullableString($row, 'email'),
            socialLinks: self::decodeSocialLinks(Row::nullableString($row, 'social_links')),
            state: BusinessState::from(Row::string($row, 'status')),
            publishedAt: DatabaseTime::parse(Row::nullableString($row, 'published_at')),
            unpublishedAt: DatabaseTime::parse(Row::nullableString($row, 'unpublished_at')),
            closedAt: DatabaseTime::parse(Row::nullableString($row, 'closed_at')),
            closureReason: Row::nullableString($row, 'closure_reason'),
            version: Row::int($row, 'version'),
            createdBy: self::nullableInt($row, 'created_by'),
            createdAt: DatabaseTime::parse(Row::string($row, 'created_at'))
                ?? throw new UnexpectedValueException('A Business row has no created_at.'),
            updatedBy: self::nullableInt($row, 'updated_by'),
            updatedAt: DatabaseTime::parse(Row::string($row, 'updated_at'))
                ?? throw new UnexpectedValueException('A Business row has no updated_at.'),
        );
    }

    /** @param array<array-key, mixed> $row */
    private static function nullableInt(array $row, string $column): ?int
    {
        $value = $row[$column] ?? null;

        return is_int($value) || is_string($value) ? (int) $value : null;
    }

    /** @return array<string, string>|null */
    private static function decodeSocialLinks(?string $stored): ?array
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $decoded = json_decode($stored, true);

        if (!is_array($decoded)) {
            return null;
        }

        $links = [];

        foreach ($decoded as $platform => $address) {
            if (is_string($platform) && is_string($address)) {
                $links[$platform] = $address;
            }
        }

        return $links === [] ? null : $links;
    }
}
