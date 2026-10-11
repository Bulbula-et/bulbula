<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Database;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\Migrations\MigrationLocator;
use Bulbula\Backend\Database\Migrations\MigrationRepository;
use Bulbula\Backend\Database\Migrations\Migrator;
use Bulbula\Backend\Exceptions\MigrationException;
use Bulbula\Backend\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The migration machinery: order, batches, reversibility and the refusal to
 * apply history out of order. These are the properties that keep a schema
 * trustworthy on a host nobody can reset.
 */
final class MigratorTest extends TestCase
{
    private string $directory = '';

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bulbula-migrations-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    #[Test]
    public function it_applies_pending_migrations_in_filename_order(): void
    {
        $this->migration('2026_01_01_000000_create_first', 'CREATE TABLE first (id INTEGER PRIMARY KEY)');
        $this->migration('2026_01_02_000000_create_second', 'CREATE TABLE second (id INTEGER PRIMARY KEY)');

        $applied = $this->migrator()->apply();

        self::assertSame(
            ['2026_01_01_000000_create_first.php', '2026_01_02_000000_create_second.php'],
            $applied,
        );
    }

    #[Test]
    public function it_records_what_it_applied_in_one_batch(): void
    {
        $this->migration('2026_01_01_000000_create_first', 'CREATE TABLE first (id INTEGER PRIMARY KEY)');

        $migrator = $this->migrator();
        $migrator->apply();

        self::assertSame(['2026_01_01_000000_create_first.php' => 1], $migrator->applied());
        self::assertSame([], $migrator->pending());
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $this->migration('2026_01_01_000000_create_first', 'CREATE TABLE first (id INTEGER PRIMARY KEY)');

        $migrator = $this->migrator();
        $migrator->apply();

        self::assertSame([], $migrator->apply());
    }

    #[Test]
    public function a_second_run_after_a_new_file_is_a_second_batch(): void
    {
        $this->migration('2026_01_01_000000_create_first', 'CREATE TABLE first (id INTEGER PRIMARY KEY)');
        $migrator = $this->migrator();
        $migrator->apply();

        $this->migration('2026_01_02_000000_create_second', 'CREATE TABLE second (id INTEGER PRIMARY KEY)');
        $migrator->apply();

        self::assertSame(
            ['2026_01_01_000000_create_first.php' => 1, '2026_01_02_000000_create_second.php' => 2],
            $migrator->applied(),
        );
    }

    #[Test]
    public function it_refuses_a_migration_that_sorts_before_an_applied_one(): void
    {
        // A branch from last week, or a deploy that went backwards. Applying it
        // now would rewrite history out of order.
        $this->migration('2026_01_02_000000_create_second', 'CREATE TABLE second (id INTEGER PRIMARY KEY)');
        $migrator = $this->migrator();
        $migrator->apply();

        $this->migration('2026_01_01_000000_create_first', 'CREATE TABLE first (id INTEGER PRIMARY KEY)');

        self::assertSame(['2026_01_01_000000_create_first.php'], $migrator->outOfOrder());

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('sort before an applied one');

        $migrator->apply();
    }

    #[Test]
    public function a_failing_migration_is_not_recorded_as_applied(): void
    {
        $this->migration('2026_01_01_000000_broken', 'CREATE TABLE (this is not SQL)');

        $migrator = $this->migrator();

        try {
            $migrator->apply();
            self::fail('A broken migration must not be applied quietly.');
        } catch (MigrationException $error) {
            self::assertStringContainsString('2026_01_01_000000_broken.php', $error->getMessage());
        }

        self::assertSame([], $migrator->applied());
        self::assertSame(['2026_01_01_000000_broken.php'], $migrator->pending());
    }

    #[Test]
    public function rollback_reverts_the_last_batch_and_forgets_it(): void
    {
        $this->migration('2026_01_01_000000_create_first', 'CREATE TABLE first (id INTEGER PRIMARY KEY)', 'DROP TABLE first');
        $migrator = $this->migrator();
        $migrator->apply();

        self::assertSame(['2026_01_01_000000_create_first.php'], $migrator->rollback());
        self::assertSame([], $migrator->applied());
        self::assertFalse($this->tableExists('first'));
    }

    #[Test]
    public function rollback_takes_steps_as_batches_not_as_files(): void
    {
        $this->migration('2026_01_01_000000_create_first', 'CREATE TABLE first (id INTEGER PRIMARY KEY)', 'DROP TABLE first');
        $migrator = $this->migrator();
        $migrator->apply();

        $this->migration('2026_01_02_000000_create_second', 'CREATE TABLE second (id INTEGER PRIMARY KEY)', 'DROP TABLE second');
        $migrator->apply();

        self::assertSame(['2026_01_02_000000_create_second.php'], $migrator->rollback(1));
        self::assertSame(['2026_01_01_000000_create_first.php' => 1], $migrator->applied());
    }

    #[Test]
    public function rollback_on_an_empty_history_is_not_an_error(): void
    {
        self::assertSame([], $this->migrator()->rollback());
    }

    #[Test]
    public function status_reports_every_file_with_its_state(): void
    {
        $this->migration('2026_01_01_000000_create_first', 'CREATE TABLE first (id INTEGER PRIMARY KEY)');
        $this->migration('2026_01_02_000000_create_second', 'CREATE TABLE second (id INTEGER PRIMARY KEY)');

        $migrator = $this->migrator();
        $migrator->apply();

        self::assertSame([
            ['name' => '2026_01_01_000000_create_first.php', 'state' => 'applied', 'batch' => 1],
            ['name' => '2026_01_02_000000_create_second.php', 'state' => 'applied', 'batch' => 1],
        ], $migrator->status());
    }

    #[Test]
    public function a_file_that_is_not_named_like_a_migration_is_refused(): void
    {
        file_put_contents($this->directory . '/create_first.php', '<?php return null;');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('must be named');

        $this->migrator()->apply();
    }

    #[Test]
    public function a_file_that_returns_the_wrong_thing_is_refused(): void
    {
        $this->migration('2026_01_01_000000_wrong', 'SELECT 1', 'SELECT 1', 'return "not a migration";');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('must return a');

        $this->migrator()->apply();
    }

    /** Writes a migration file for the duration of one test. */
    private function migration(string $name, string $up, string $down = 'SELECT 1', ?string $body = null): void
    {
        $contents = $body ?? 'return new class implements Bulbula\Backend\Database\Migrations\Migration {'
            . ' public function up(Bulbula\Backend\Database\Connection $connection): void { $connection->pdo()->exec('
            . var_export($up, true) . '); }'
            . ' public function down(Bulbula\Backend\Database\Connection $connection): void { $connection->pdo()->exec('
            . var_export($down, true) . '); } };';

        file_put_contents(
            $this->directory . '/' . $name . '.php',
            "<?php\n\ndeclare(strict_types=1);\n\n" . $contents . "\n",
        );
    }

    private function migrator(): Migrator
    {
        $connection = $this->connection();

        return new Migrator(
            $connection,
            new MigrationLocator($this->directory),
            new MigrationRepository($connection),
            $this->clock(),
        );
    }

    /** One connection per test: the migrator and the assertions must see the same database. */
    private function connection(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $basePath = sys_get_temp_dir() . '/bulbula-migrator-' . bin2hex(random_bytes(4));
        mkdir($basePath . '/config', 0777, true);

        file_put_contents(
            $basePath . '/config/database.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn ['driver' => 'sqlite', 'sqlite_path' => ':memory:'];\n",
        );

        $config = new Config($basePath);
        $config->load('database');

        return $this->connection = new Connection($config);
    }

    private function tableExists(string $table): bool
    {
        return $this->connection()->hasTable($table);
    }

    private function clock(): Clock
    {
        $now = new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('UTC'));

        return new class ($now) implements Clock {
            public function __construct(private readonly DateTimeImmutable $now)
            {
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}
