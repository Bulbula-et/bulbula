<?php

declare(strict_types=1);

namespace Bulbula\Backend\Repositories\Identity;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\DatabaseTime;
use Bulbula\Backend\Database\Row;
use Bulbula\Backend\Domain\Identity\Customer;
use Bulbula\Backend\Domain\Identity\CustomerState;
use Bulbula\Backend\Domain\Identity\Email;
use DateTimeImmutable;

/**
 * Customers and the provider identities that belong to them.
 *
 * Both tables live here because a provider identity has no meaning without its
 * Customer — it is part of the same aggregate, not an entity of its own
 * (data-model §5.2).
 *
 * An address is unique across the table, so `findByEmail` is the resolved
 * answer rather than a guess among candidates (auth-identity §5.3).
 */
final class CustomerRepository
{
    private const TABLE = 'customers';

    private const IDENTITIES = 'provider_identities';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?Customer
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id',
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** A **verified** address, which is the only kind that resolves to an account (NA-1). */
    public function findByEmail(Email $email): ?Customer
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE email = :email AND email_verified_at IS NOT NULL',
        );
        $statement->execute(['email' => $email->value]);

        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function create(Email $email, DateTimeImmutable $now, ?string $displayName = null): Customer
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO ' . self::TABLE
            . ' (public_ref, email, email_verified_at, display_name, state, created_at, updated_at, last_seen_at)'
            . ' VALUES (:ref, :email, :verified_at, :display_name, :state, :created_at, :updated_at, :last_seen_at)',
        );

        // One stamp, three slots: PDO_MySQL binds a name once per statement.
        $stamp = DatabaseTime::format($now);

        $statement->execute([
            'ref'          => $this->newPublicRef(),
            'email'        => $email->value,
            'verified_at'  => $stamp,
            'display_name' => $displayName,
            'state'        => CustomerState::Active->value,
            'created_at'   => $stamp,
            'updated_at'   => $stamp,
            'last_seen_at' => $stamp,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return $this->findById($id) ?? throw new \RuntimeException('The Customer could not be read back after creation.');
    }

    /** The Customer a provider identity points at, if one exists. */
    public function findIdentity(string $provider, string $subject): ?int
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT customer_id FROM ' . self::IDENTITIES . ' WHERE provider = :provider AND subject = :subject',
        );
        $statement->execute(['provider' => $provider, 'subject' => $subject]);

        $id = $statement->fetchColumn();

        return is_int($id) || is_string($id) ? (int) $id : null;
    }

    public function linkIdentity(
        int $customerId,
        string $provider,
        string $subject,
        Email $emailAtProvider,
        DateTimeImmutable $now,
    ): void {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO ' . self::IDENTITIES
            . ' (customer_id, provider, subject, email_at_provider, linked_at, last_used_at)'
            . ' VALUES (:customer_id, :provider, :subject, :email, :linked_at, :last_used_at)',
        );

        $stamp = DatabaseTime::format($now);

        $statement->execute([
            'customer_id'  => $customerId,
            'provider'     => $provider,
            'subject'      => $subject,
            'email'        => $emailAtProvider->value,
            'linked_at'    => $stamp,
            'last_used_at' => $stamp,
        ]);
    }

    public function touchLastSeen(int $customerId, DateTimeImmutable $now): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE ' . self::TABLE . ' SET last_seen_at = :now WHERE id = :id',
        );
        $statement->execute(['now' => DatabaseTime::format($now), 'id' => $customerId]);
    }

    /**
     * A row becomes a Customer here, in the storage layer, rather than inside
     * the domain object: the domain should not know what a database row looks
     * like (STR-3, DB-10).
     *
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): Customer
    {
        return new Customer(
            id: Row::int($row, 'id'),
            publicRef: Row::string($row, 'public_ref'),
            email: Email::fromString(Row::string($row, 'email')),
            displayName: Row::nullableString($row, 'display_name'),
            state: CustomerState::from(Row::string($row, 'state')),
            createdAt: DatabaseTime::parse(Row::string($row, 'created_at'))
                ?? throw new \UnexpectedValueException('A Customer row has no created_at.'),
        );
    }

    /**
     * A public reference, for the places an identifier must leave the system.
     *
     * Not a credential and not guessable material: a sequence would publish how
     * many Customers exist and in what order they arrived, which is business
     * information nobody is entitled to.
     */
    private function newPublicRef(): string
    {
        return 'cus_' . bin2hex(random_bytes(10));
    }
}
