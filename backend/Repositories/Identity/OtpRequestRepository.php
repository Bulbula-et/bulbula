<?php

declare(strict_types=1);

namespace Bulbula\Backend\Repositories\Identity;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\DatabaseTime;
use Bulbula\Backend\Database\Row;
use Bulbula\Backend\Domain\Identity\Email;
use Bulbula\Backend\Domain\Identity\OtpChallenge;
use Bulbula\Backend\Domain\Identity\PendingLink;
use DateTimeImmutable;

/**
 * Issued sign-in codes (data-model §5.3).
 *
 * This table is also the rate-limit evidence for the address it belongs to:
 * how many codes were issued for an address, from which network, and when the
 * last one went out. Those are questions about *these rows*, so answering them
 * here avoids a second store that could drift out of step with this one.
 */
final class OtpRequestRepository
{
    private const TABLE = 'otp_requests';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function issue(
        Email $email,
        string $codeHash,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
        ?string $sessionBinding,
        ?string $sourceNetwork,
        ?PendingLink $pendingLink = null,
    ): void {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO ' . self::TABLE
            . ' (email, code_hash, issued_at, expires_at, consumed_at, attempts, session_binding, source_network,'
            . ' purpose, pending_provider, pending_subject)'
            . ' VALUES (:email, :code_hash, :issued_at, :expires_at, NULL, 0, :binding, :network,'
            . ' :purpose, :provider, :subject)',
        );

        $statement->execute([
            'email'      => $email->value,
            'code_hash'  => $codeHash,
            'issued_at'  => DatabaseTime::format($issuedAt),
            'expires_at' => DatabaseTime::format($expiresAt),
            'binding'    => $sessionBinding,
            'network'    => $sourceNetwork,
            'purpose'    => $pendingLink === null ? OtpChallenge::SIGN_IN : OtpChallenge::LINK,
            'provider'   => $pendingLink?->provider,
            'subject'    => $pendingLink?->subject,
        ]);
    }

    /** The newest unconsumed code for an address, whether or not it has expired. */
    public function latestUnconsumed(Email $email): ?OtpChallenge
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE
            . ' WHERE email = :email AND consumed_at IS NULL ORDER BY id DESC LIMIT 1',
        );
        $statement->execute(['email' => $email->value]);

        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Consume a code. Returns false when somebody else got there first.
     *
     * The `consumed_at IS NULL` in the WHERE clause is the whole single-use
     * guarantee (OTP-3): two requests racing on one code cannot both succeed,
     * because only one of them changes a row.
     */
    public function consume(int $id, DateTimeImmutable $now): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE ' . self::TABLE . ' SET consumed_at = :now WHERE id = :id AND consumed_at IS NULL',
        );
        $statement->execute(['now' => DatabaseTime::format($now), 'id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function recordFailedAttempt(int $id): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE ' . self::TABLE . ' SET attempts = attempts + 1 WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): OtpChallenge
    {
        return new OtpChallenge(
            id: Row::int($row, 'id'),
            email: Email::fromString(Row::string($row, 'email')),
            codeHash: Row::string($row, 'code_hash'),
            issuedAt: DatabaseTime::parse(Row::string($row, 'issued_at'))
                ?? throw new \UnexpectedValueException('An OTP row has no issued_at.'),
            expiresAt: DatabaseTime::parse(Row::string($row, 'expires_at'))
                ?? throw new \UnexpectedValueException('An OTP row has no expires_at.'),
            consumedAt: DatabaseTime::parse(Row::nullableString($row, 'consumed_at')),
            attempts: Row::int($row, 'attempts'),
            sessionBinding: Row::nullableString($row, 'session_binding'),
            purpose: Row::string($row, 'purpose'),
            pendingProvider: Row::nullableString($row, 'pending_provider'),
            pendingSubject: Row::nullableString($row, 'pending_subject'),
        );
    }

    public function countIssuedFor(Email $email, DateTimeImmutable $since): int
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE email = :email AND issued_at >= :since',
        );
        $statement->execute(['email' => $email->value, 'since' => DatabaseTime::format($since)]);

        $count = $statement->fetchColumn();

        return is_int($count) || is_string($count) ? (int) $count : 0;
    }

    public function countIssuedFrom(string $network, DateTimeImmutable $since): int
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE source_network = :network AND issued_at >= :since',
        );
        $statement->execute(['network' => $network, 'since' => DatabaseTime::format($since)]);

        $count = $statement->fetchColumn();

        return is_int($count) || is_string($count) ? (int) $count : 0;
    }

    public function lastIssuedAt(Email $email): ?DateTimeImmutable
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT issued_at FROM ' . self::TABLE . ' WHERE email = :email ORDER BY id DESC LIMIT 1',
        );
        $statement->execute(['email' => $email->value]);

        $stored = $statement->fetchColumn();

        return is_string($stored) ? DatabaseTime::parse($stored) : null;
    }
}
