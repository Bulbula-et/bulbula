<?php

declare(strict_types=1);

namespace Bulbula\Backend\Repositories\Operations;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\DatabaseTime;
use Bulbula\Backend\Database\Row;
use Bulbula\Backend\Domain\Operations\PermissionMethod;
use Bulbula\Backend\Domain\Operations\PermissionRecord;
use Bulbula\Backend\Domain\Operations\PermissionState;
use DateTimeImmutable;
use UnexpectedValueException;

/**
 * Every SQL statement that touches `permission_records` (TR-16).
 *
 * `findLatestForBusiness` is the method the publication gate reads, and its
 * ordering is part of the rule: the **most recent** record decides. Ordering
 * by `created_at` alone would tie on same-second insertions, so the id breaks
 * the tie — the record written last is the record in force.
 */
final class PermissionRecordRepository
{
    private const TABLE = 'permission_records';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?PermissionRecord
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id',
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findLatestForBusiness(int $businessId): ?PermissionRecord
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE
            . ' WHERE business_id = :business ORDER BY created_at DESC, id DESC LIMIT 1',
        );
        $statement->execute(['business' => $businessId]);

        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function grant(
        int $businessId,
        string $personName,
        ?string $personRole,
        PermissionMethod $method,
        DateTimeImmutable $grantedAt,
        DateTimeImmutable $now,
        ?int $obtainedBy = null,
        ?string $scopeNote = null,
        ?string $evidenceReference = null,
    ): PermissionRecord {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO ' . self::TABLE
            . ' (business_id, person_name, person_role, method, granted_at, obtained_by, scope_note,'
            . ' state, evidence_reference, created_at)'
            . ' VALUES (:business, :person, :role, :method, :granted_at, :obtained_by, :scope,'
            . ' :state, :evidence, :now)',
        );

        $statement->execute([
            'business'   => $businessId,
            'person'     => $personName,
            'role'       => $personRole,
            'method'     => $method->value,
            'granted_at' => DatabaseTime::format($grantedAt),
            'obtained_by' => $obtainedBy,
            'scope'      => $scopeNote,
            'state'      => PermissionState::Granted->value,
            'evidence'   => $evidenceReference,
            'now'        => DatabaseTime::format($now),
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return $this->findById($id)
            ?? throw new UnexpectedValueException('The Permission record could not be read back after creation.');
    }

    /** A withdrawal is recorded on the row, never a deletion (PRD TS-5). */
    public function withdraw(int $recordId, string $reason, DateTimeImmutable $now): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE ' . self::TABLE
            . ' SET state = :state, withdrawn_at = :now, withdrawal_reason = :reason'
            . ' WHERE id = :id',
        );

        $statement->execute([
            'state'  => PermissionState::Withdrawn->value,
            'now'    => DatabaseTime::format($now),
            'reason' => $reason,
            'id'     => $recordId,
        ]);
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): PermissionRecord
    {
        return new PermissionRecord(
            id: Row::int($row, 'id'),
            businessId: Row::int($row, 'business_id'),
            personName: Row::string($row, 'person_name'),
            personRole: Row::nullableString($row, 'person_role'),
            method: PermissionMethod::from(Row::string($row, 'method')),
            grantedAt: DatabaseTime::parse(Row::string($row, 'granted_at'))
                ?? throw new UnexpectedValueException('A Permission record has no granted_at.'),
            obtainedBy: self::nullableInt($row, 'obtained_by'),
            scopeNote: Row::nullableString($row, 'scope_note'),
            state: PermissionState::from(Row::string($row, 'state')),
            withdrawnAt: DatabaseTime::parse(Row::nullableString($row, 'withdrawn_at')),
            withdrawalReason: Row::nullableString($row, 'withdrawal_reason'),
            evidenceReference: Row::nullableString($row, 'evidence_reference'),
            createdAt: DatabaseTime::parse(Row::string($row, 'created_at'))
                ?? throw new UnexpectedValueException('A Permission record has no created_at.'),
        );
    }

    /** @param array<array-key, mixed> $row */
    private static function nullableInt(array $row, string $column): ?int
    {
        $value = $row[$column] ?? null;

        return is_int($value) || is_string($value) ? (int) $value : null;
    }
}
