<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Services\Platform;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\Migrations\MigrationLocator;
use Bulbula\Backend\Database\Migrations\MigrationRepository;
use Bulbula\Backend\Database\Migrations\Migrator;
use Bulbula\Backend\Domain\Platform\ActorType;
use Bulbula\Backend\Repositories\Platform\AuditEntryRepository;
use Bulbula\Backend\Services\Platform\AuditRecorder;
use Bulbula\Backend\Support\SystemClock;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The audit writer against the real schema: what it records, what it refuses,
 * and the promise that a failure to record is a failure of the action.
 *
 * The suite runs on SQLite, so the shipped MySQL-flavoured migration is
 * exercised through the same `driver()` branch a production host would take —
 * which is why migration portability (TR-175) is asserted separately below.
 */
final class AuditRecorderTest extends TestCase
{
    private ?Connection $connection = null;

    #[Test]
    public function it_records_actor_action_target_time_and_reason(): void
    {
        $recorder = $this->recorder();
        $before = (new SystemClock())->now();

        $entry = $recorder->record(
            ActorType::Operator,
            41,
            'listing.published',
            'listing',
            12,
            'Source verified',
        );

        self::assertSame(ActorType::Operator, $entry->actorType);
        self::assertSame(41, $entry->actorId);

        $row = $this->row();

        self::assertSame('listing.published', $row['action']);
        self::assertSame('operator', $row['actor_type']);
        self::assertSame('12', (string) $row['target_id']);
        self::assertSame('Source verified', $row['reason']);
        self::assertGreaterThanOrEqual($before->getTimestamp(), strtotime((string) $row['occurred_at'] . ' UTC'));
    }

    #[Test]
    public function it_names_only_an_action_for_a_guest(): void
    {
        // A guest has no account to name; the entry still says a guest did it.
        $entry = $this->recorder()->record(ActorType::Guest, null, 'session.started');

        self::assertNull($entry->actorId);
        self::assertSame('guest', $this->row()['actor_type']);
    }

    #[Test]
    public function it_records_a_platform_action_without_naming_a_person(): void
    {
        // An owner decision (2026-10-11): when the platform acts by itself —
        // a scheduled job, a retention sweep — the entry says so. Naming the
        // Administrator would blame a person for something a machine did.
        $entry = $this->recorder()->record(ActorType::System, null, 'listing.expired', 'listing', 12, 'Nightly sweep');

        self::assertNull($entry->actorId);
        self::assertSame('system', $this->row()['actor_type']);
    }

    #[Test]
    public function the_platform_cannot_be_given_a_person_s_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot name an actor id');

        $this->recorder()->record(ActorType::System, 7, 'listing.expired');
    }

    #[Test]
    public function an_authenticated_actor_without_an_id_is_refused(): void
    {
        // "Who acted" is the point of the trail: an anonymous operator entry
        // would be an entry that cannot be acted on.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs the id of the actor');

        $this->recorder()->record(ActorType::Administrator, null, 'role.assigned');
    }

    #[Test]
    public function a_guest_with_an_actor_id_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot name an actor id');

        $this->recorder()->record(ActorType::Guest, 4, 'page.viewed');
    }

    #[Test]
    public function an_entry_without_an_action_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs an action');

        $this->recorder()->record(ActorType::Operator, 1, '   ');
    }

    #[Test]
    public function a_target_id_without_a_target_type_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('both a type and an id');

        $this->recorder()->record(ActorType::Operator, 1, 'listing.updated', null, 12);
    }

    #[Test]
    public function an_unwritable_audit_fails_the_action(): void
    {
        // TR-08: no silent success. Dropping the table is the plainest way to
        // make the write fail; the exception must reach the caller, so the
        // caller's transaction rolls back with it.
        $connection = $this->connection();
        $recorder = new AuditRecorder(new AuditEntryRepository($connection), new SystemClock());

        $connection->pdo()->exec('DROP TABLE audit_entries');

        $this->expectException(\PDOException::class);

        $recorder->record(ActorType::Operator, 7, 'listing.updated');
    }

    #[Test]
    public function it_writes_inside_the_callers_transaction(): void
    {
        // TR-08 asks for the entry and the change to commit together. The
        // recorder opens no transaction of its own, so a rolled-back action
        // leaves no audit entry — which is only true while this holds.
        $connection = $this->connection();
        $recorder = new AuditRecorder(new AuditEntryRepository($connection), new SystemClock());

        $connection->pdo()->beginTransaction();
        $recorder->record(ActorType::Operator, 7, 'listing.updated', 'listing', 3);
        $connection->pdo()->rollBack();

        self::assertSame(0, $this->entryCount());
    }

    #[Test]
    public function the_shipped_migration_applies_and_reverts_on_the_test_engine(): void
    {
        // Migration portability (TR-175): the file that will run on MariaDB
        // runs, unchanged, on the engine the suite uses.
        $connection = $this->connection();
        $repository = new MigrationRepository($connection);
        $migrator = new Migrator(
            $connection,
            new MigrationLocator(dirname(__DIR__, 5) . '/database/migrations'),
            $repository,
            new SystemClock(),
        );

        self::assertTrue($connection->hasTable('audit_entries'));

        self::assertSame(['2026_10_11_120000_create_audit_entries.php'], $migrator->rollback());
        self::assertFalse($connection->hasTable('audit_entries'));

        self::assertSame(['2026_10_11_120000_create_audit_entries.php'], $migrator->apply());
        self::assertTrue($connection->hasTable('audit_entries'));
    }

    /** The recorder under test, against a migrated in-memory database. */
    private function recorder(): AuditRecorder
    {
        return new AuditRecorder(new AuditEntryRepository($this->connection()), new SystemClock());
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $rows = $this->connection()->pdo()
            ->query('SELECT * FROM audit_entries ORDER BY id DESC LIMIT 1')
            ->fetchAll(PDO::FETCH_ASSOC);

        self::assertNotSame([], $rows, 'No audit entry was written.');

        return $rows[0];
    }

    private function entryCount(): int
    {
        return (int) $this->connection()->pdo()->query('SELECT COUNT(*) FROM audit_entries')->fetchColumn();
    }

    /** One migrated database per test: writer and assertions must see the same rows. */
    private function connection(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $basePath = sys_get_temp_dir() . '/bulbula-audit-' . bin2hex(random_bytes(4));
        mkdir($basePath . '/config', 0777, true);

        file_put_contents(
            $basePath . '/config/database.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn ['driver' => 'sqlite', 'sqlite_path' => ':memory:'];\n",
        );

        $config = new Config($basePath);
        $config->load('database');

        $connection = new Connection($config);
        $migrator = new Migrator(
            $connection,
            new MigrationLocator(dirname(__DIR__, 5) . '/database/migrations'),
            new MigrationRepository($connection),
            new SystemClock(),
        );
        $migrator->apply();

        return $this->connection = $connection;
    }
}
