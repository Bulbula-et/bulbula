<?php

declare(strict_types=1);

namespace Bulbula\Backend\Repositories\Directory;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\DatabaseTime;
use Bulbula\Backend\Database\Row;
use Bulbula\Backend\Domain\Directory\Branch;
use Bulbula\Backend\Domain\Directory\BranchState;
use DateTimeImmutable;
use UnexpectedValueException;

/**
 * Every SQL statement that touches `branches` (TR-16).
 *
 * The invariants this class serves are the model's: a Business always has at
 * least one Branch (D-03), and exactly one Branch is primary. Neither is a
 * portable database constraint — SQLite and MySQL have no shared partial
 * unique index — so the reading side counts and the writing side is made to
 * agree, and both are asserted by tests (`PublicationGate` refuses a Listing
 * whose primary count is not exactly one).
 */
final class BranchRepository
{
    private const TABLE = 'branches';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?Branch
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id',
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<Branch> */
    public function listForBusiness(int $businessId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE business_id = :business ORDER BY is_primary DESC, id ASC',
        );
        $statement->execute(['business' => $businessId]);

        $branches = [];

        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $branches[] = $this->hydrate($row);
            }
        }

        return $branches;
    }

    public function countForBusiness(int $businessId): int
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE business_id = :business',
        );
        $statement->execute(['business' => $businessId]);

        return (int) $statement->fetchColumn();
    }

    public function primaryCount(int $businessId): int
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE business_id = :business AND is_primary = 1',
        );
        $statement->execute(['business' => $businessId]);

        return (int) $statement->fetchColumn();
    }

    public function create(
        int $businessId,
        int $areaId,
        int $subCityId,
        ?string $label,
        ?string $addressLine,
        ?float $latitude,
        ?float $longitude,
        ?string $phone,
        ?string $phoneSecondary,
        ?string $email,
        bool $isPrimary,
        DateTimeImmutable $now,
        BranchState $state = BranchState::Active,
    ): Branch {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO ' . self::TABLE
            . ' (business_id, area_id, sub_city_id, label, address_line, latitude, longitude,'
            . ' phone, phone_secondary, email, status, is_primary, created_at, updated_at)'
            . ' VALUES (:business, :area, :sub_city, :label, :address, :latitude, :longitude,'
            . ' :phone, :phone_secondary, :email, :status, :primary, :now, :now)',
        );

        $statement->execute([
            'business'        => $businessId,
            'area'            => $areaId,
            'sub_city'        => $subCityId,
            'label'           => $label,
            'address'         => $addressLine,
            'latitude'        => $latitude,
            'longitude'       => $longitude,
            'phone'           => $phone,
            'phone_secondary' => $phoneSecondary,
            'email'           => $email,
            'status'          => $state->value,
            'primary'         => $isPrimary ? 1 : 0,
            'now'             => DatabaseTime::format($now),
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return $this->findById($id)
            ?? throw new UnexpectedValueException('The Branch could not be read back after creation.');
    }

    /** Exactly one Branch is primary: the new one is set and every other is cleared. */
    public function makePrimary(int $businessId, int $branchId, DateTimeImmutable $now): void
    {
        $pdo = $this->connection->pdo();

        $clear = $pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET is_primary = 0, updated_at = :now'
            . ' WHERE business_id = :business AND is_primary = 1',
        );
        $clear->execute(['now' => DatabaseTime::format($now), 'business' => $businessId]);

        $set = $pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET is_primary = 1, updated_at = :now'
            . ' WHERE id = :id AND business_id = :business',
        );
        $set->execute(['now' => DatabaseTime::format($now), 'id' => $branchId, 'business' => $businessId]);
    }

    /**
     * A row becomes a Branch here, not inside the domain object (STR-3, DB-10).
     *
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): Branch
    {
        return new Branch(
            id: Row::int($row, 'id'),
            businessId: Row::int($row, 'business_id'),
            areaId: Row::int($row, 'area_id'),
            subCityId: Row::int($row, 'sub_city_id'),
            label: Row::nullableString($row, 'label'),
            addressLine: Row::nullableString($row, 'address_line'),
            latitude: self::nullableFloat($row, 'latitude'),
            longitude: self::nullableFloat($row, 'longitude'),
            phone: Row::nullableString($row, 'phone'),
            phoneSecondary: Row::nullableString($row, 'phone_secondary'),
            email: Row::nullableString($row, 'email'),
            state: BranchState::from(Row::string($row, 'status')),
            isPrimary: Row::int($row, 'is_primary') === 1,
            createdAt: DatabaseTime::parse(Row::string($row, 'created_at'))
                ?? throw new UnexpectedValueException('A Branch row has no created_at.'),
            updatedAt: DatabaseTime::parse(Row::string($row, 'updated_at'))
                ?? throw new UnexpectedValueException('A Branch row has no updated_at.'),
        );
    }

    /** @param array<array-key, mixed> $row */
    private static function nullableFloat(array $row, string $column): ?float
    {
        $value = $row[$column] ?? null;

        return is_int($value) || is_float($value) || is_string($value) ? (float) $value : null;
    }
}
