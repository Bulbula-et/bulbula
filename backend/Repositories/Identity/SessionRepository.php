<?php

declare(strict_types=1);

namespace Bulbula\Backend\Repositories\Identity;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\DatabaseTime;
use Bulbula\Backend\Database\Row;
use Bulbula\Backend\Domain\Identity\PrincipalType;
use Bulbula\Backend\Domain\Identity\Session;
use Bulbula\Backend\Domain\Identity\Surface;
use DateTimeImmutable;

/**
 * The one session record, whichever transport reached it (TD-02).
 *
 * Rows are addressed by the hash of a token, never by the token: a copy of
 * this table is a list of expired conversations, not a set of working
 * credentials (S-3).
 *
 * `principal_id` carries no foreign key on purpose. A session can belong to a
 * Customer or to a staff user, and a key can only point at one table —
 * TR-167's rule is that this is stated rather than implied. The consequence is
 * deliberate: deleting a principal does not silently delete its sessions;
 * revocation is an explicit act (S-7).
 */
final class SessionRepository
{
    private const TABLE = 'sessions';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(
        PrincipalType $principalType,
        int $principalId,
        string $tokenHash,
        Surface $surface,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
        ?string $userAgent,
        ?string $network,
        string $csrfHash,
    ): Session {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO ' . self::TABLE
            . ' (principal_type, principal_id, token_hash, surface, created_at, last_active_at, expires_at,'
            . ' revoked_at, user_agent, network_marker, csrf_hash)'
            . ' VALUES (:type, :principal, :token_hash, :surface, :now, :now, :expires_at, NULL, :agent, :network, :csrf)',
        );

        $statement->execute([
            'type'        => $principalType->value,
            'principal'   => $principalId,
            'token_hash'  => $tokenHash,
            'surface'     => $surface->value,
            'now'         => DatabaseTime::format($now),
            'expires_at'  => DatabaseTime::format($expiresAt),
            'agent'       => $userAgent,
            'network'     => $network,
            'csrf'        => $csrfHash,
        ]);

        return $this->findById((int) $this->connection->pdo()->lastInsertId())
            ?? throw new \RuntimeException('The session could not be read back after creation.');
    }

    public function findById(int $id): ?Session
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id',
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByTokenHash(string $tokenHash): ?Session
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE token_hash = :token_hash',
        );
        $statement->execute(['token_hash' => $tokenHash]);

        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): Session
    {
        return new Session(
            id: Row::int($row, 'id'),
            principalType: PrincipalType::from(Row::string($row, 'principal_type')),
            principalId: Row::int($row, 'principal_id'),
            surface: Surface::from(Row::string($row, 'surface')),
            createdAt: DatabaseTime::parse(Row::string($row, 'created_at'))
                ?? throw new \UnexpectedValueException('A session row has no created_at.'),
            lastActiveAt: DatabaseTime::parse(Row::string($row, 'last_active_at'))
                ?? throw new \UnexpectedValueException('A session row has no last_active_at.'),
            expiresAt: DatabaseTime::parse(Row::string($row, 'expires_at'))
                ?? throw new \UnexpectedValueException('A session row has no expires_at.'),
            revokedAt: DatabaseTime::parse(Row::nullableString($row, 'revoked_at')),
            csrfHash: Row::nullableString($row, 'csrf_hash'),
        );
    }

    public function touch(int $id, DateTimeImmutable $now): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE ' . self::TABLE . ' SET last_active_at = :now WHERE id = :id',
        );
        $statement->execute(['now' => DatabaseTime::format($now), 'id' => $id]);
    }

    public function revoke(int $id, DateTimeImmutable $now): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE ' . self::TABLE . ' SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL',
        );
        $statement->execute(['now' => DatabaseTime::format($now), 'id' => $id]);

        return $statement->rowCount() === 1;
    }

    /** Sign-out everywhere for one principal — what account deletion needs (S-7). */
    public function revokeAllFor(PrincipalType $principalType, int $principalId, DateTimeImmutable $now): int
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE ' . self::TABLE . ' SET revoked_at = :now'
            . ' WHERE principal_type = :type AND principal_id = :principal AND revoked_at IS NULL',
        );
        $statement->execute([
            'now'       => DatabaseTime::format($now),
            'type'      => $principalType->value,
            'principal' => $principalId,
        ]);

        return $statement->rowCount();
    }
}
