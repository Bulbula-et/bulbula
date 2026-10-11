<?php

declare(strict_types=1);

namespace Bulbula\Tests\Feature\Console;

use Bulbula\Backend\Console\Output;
use Bulbula\Backend\Core\Application;
use Bulbula\Tests\Support\ApplicationTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `bin/console`, through the real composition root: the command table, the
 * answer to a name nobody wired, and the two commands that say what the schema
 * is and how to undo it.
 *
 * The database is a temporary SQLite file, so a run here applies the *shipped*
 * migration — the same file a host will run — rather than a fixture.
 */
final class ConsoleCommandsTest extends ApplicationTestCase
{
    private string $database = '';

    private ?Application $booted = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = sys_get_temp_dir() . '/bulbula-console-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (is_file($this->database)) {
            unlink($this->database);
        }

        parent::tearDown();
    }

    #[Test]
    public function the_composition_root_wires_every_command_the_console_route_file_declares(): void
    {
        // A name in routes/console.php with no factory is a boot-time error, so
        // reaching this assertion at all is half the test.
        $names = $this->booted()->console($this->capture())->names();

        self::assertSame(['migrate', 'migration:status', 'rollback'], $names);
    }

    #[Test]
    public function migrate_applies_the_shipped_migration_and_is_idempotent(): void
    {
        $first = $this->capture();

        self::assertSame(0, $this->console(['migrate'], $first));
        self::assertStringContainsString('2026_10_11_120000_create_audit_entries.php', $first->stdout);
        self::assertStringContainsString('1 migration(s) applied', $first->stdout);
        self::assertSame('', $first->stderr);

        $second = $this->capture();

        self::assertSame(0, $this->console(['migrate'], $second));
        self::assertStringContainsString('Nothing to migrate', $second->stdout);
    }

    #[Test]
    public function migration_status_reports_an_applied_migration_with_its_batch(): void
    {
        $this->console(['migrate'], $this->capture());
        $output = $this->capture();

        self::assertSame(0, $this->console(['migration:status'], $output));

        self::assertStringContainsString('applied', $output->stdout);
        self::assertStringContainsString('batch 1', $output->stdout);
        self::assertSame('', $output->stderr);
    }

    #[Test]
    public function rollback_reverts_the_last_batch_and_says_what_it_reverted(): void
    {
        $this->console(['migrate'], $this->capture());
        $output = $this->capture();

        self::assertSame(0, $this->console(['rollback'], $output));
        self::assertStringContainsString('reverted 2026_10_11_120000_create_audit_entries.php', $output->stdout);
        self::assertStringContainsString('1 migration(s) reverted', $output->stdout);

        $status = $this->capture();
        self::assertSame(0, $this->console(['migration:status'], $status));
        self::assertStringContainsString('pending', $status->stdout);
    }

    #[Test]
    public function rollback_refuses_a_step_count_that_is_not_a_positive_number(): void
    {
        $output = $this->capture();

        self::assertSame(1, $this->console(['rollback', '--steps=0'], $output));
        self::assertStringContainsString('--steps must be at least 1', $output->stderr);
    }

    #[Test]
    public function an_unknown_command_fails_loudly_instead_of_doing_nothing(): void
    {
        $output = $this->capture();

        self::assertSame(1, $this->console(['deploy:everything'], $output));
        self::assertStringContainsString('Unknown command [deploy:everything]', $output->stderr);
    }

    #[Test]
    public function the_bare_console_lists_the_commands(): void
    {
        $output = $this->capture();

        self::assertSame(0, $this->console([], $output));
        self::assertStringContainsString('migrate', $output->stdout);
        self::assertStringContainsString('rollback', $output->stdout);
    }

    /**
     * The real application, booted once per test against a temporary database.
     *
     * Booted once because `boot()` installs an error handler and PHPUnit
     * expects the suite to leave its own in place — and because one boot is
     * what a console run is.
     */
    private function booted(): Application
    {
        return $this->booted ??= $this->withEnvironment(
            ['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $this->database],
            fn (): Application => $this->newApplication(),
        );
    }

    /** @param list<string> $arguments */
    private function console(array $arguments, Output $output): int
    {
        return $this->booted()
            ->console($output)
            ->run(['bin/console', ...$arguments]);
    }

    private function capture(): Output
    {
        return new class () implements Output {
            public string $stdout = '';

            public string $stderr = '';

            public function line(string $text = ''): void
            {
                $this->stdout .= $text . "\n";
            }

            public function error(string $text): void
            {
                $this->stderr .= $text . "\n";
            }
        };
    }
}
