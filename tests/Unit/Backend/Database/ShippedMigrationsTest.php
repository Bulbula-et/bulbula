<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Database;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\Migrations\MigrationLocator;
use Bulbula\Backend\Database\Migrations\MigrationRepository;
use Bulbula\Backend\Database\Migrations\Migrator;
use Bulbula\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every migration this repository ships, applied and then reverted, on the
 * engine the suite runs on (TR-173, TR-175).
 *
 * The MariaDB branch of each file is what production will run and cannot be
 * executed here; what this test guarantees is that the files load, the pair of
 * methods is complete, and the migration is genuinely reversible — a `down()`
 * that never ran is a rollback that fails when somebody needs it most.
 */
final class ShippedMigrationsTest extends DatabaseTestCase
{
    #[Test]
    public function every_shipped_migration_applies_in_order_and_reverts_completely(): void
    {
        $connection = $this->database();
        $migrator = $this->migrator($connection);

        $files = $migrator->files();

        self::assertNotSame([], $files, 'the repository ships at least one migration');
        self::assertSame($files, array_keys($migrator->applied()), 'all of them applied, in filename order');

        foreach ($files as $file) {
            self::assertMatchesRegularExpression(
                '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/',
                $file,
                'a migration name is its position in history',
            );
        }

        $tables = $this->tables();

        foreach (['migrations', 'audit_entries', 'customers', 'provider_identities', 'otp_requests', 'sessions', 'rate_limits'] as $expected) {
            self::assertContains($expected, $tables, 'the schema the products slices need, after migrating');
        }

        self::assertSame(array_reverse($files), $migrator->rollback(count($files)));

        foreach (['audit_entries', 'customers', 'provider_identities', 'otp_requests', 'sessions', 'rate_limits'] as $reverted) {
            self::assertFalse($connection->hasTable($reverted), $reverted . ' is gone after a full rollback');
        }

        // The ledger survives its own contents: it is the runner's, not a
        // migration's, and it must still be able to say what happened.
        self::assertSame([], $migrator->applied());
    }

    #[Test]
    public function applying_everything_is_idempotent(): void
    {
        $migrator = $this->migrator($this->database());

        self::assertSame([], $migrator->apply(), 'a second run has nothing left to do');
    }

    #[Test]
    public function the_identity_schema_stores_no_credential_in_a_readable_form(): void
    {
        // AI-2 and OTP-2/S-3 expressed as facts about the schema: a column that
        // does not exist cannot be read out of a stolen database.
        $customers = $this->columnsOf('customers');

        self::assertNotContains('password', $customers);
        self::assertNotContains('password_hash', $customers);

        $codes = $this->columnsOf('otp_requests');

        self::assertContains('code_hash', $codes);
        self::assertNotContains('code', $codes, 'only the hash of a sign-in code is stored (OTP-2)');

        $sessions = $this->columnsOf('sessions');

        self::assertContains('token_hash', $sessions);
        self::assertNotContains('token', $sessions, 'only the hash of a token is stored (S-3)');
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        $columns = [];

        $statement = $this->database()->pdo()->query('PRAGMA table_info(' . $table . ')');

        foreach ($statement as $column) {
            $columns[] = (string) $column['name'];
        }

        return $columns;
    }

    /** @return list<string> */
    private function tables(): array
    {
        $names = [];

        $statement = $this->database()->pdo()->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name",
        );

        foreach ($statement as $row) {
            $names[] = (string) $row['name'];
        }

        return $names;
    }

    private function migrator(Connection $connection): Migrator
    {
        return new Migrator(
            $connection,
            new MigrationLocator($this->basePath() . '/database/migrations'),
            new MigrationRepository($connection),
            $this->clock,
        );
    }
}
