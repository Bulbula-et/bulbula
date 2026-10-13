<?php

declare(strict_types=1);

namespace Bulbula\Tests\Feature\Console;

use Bulbula\Backend\Console\Command;
use Bulbula\Backend\Console\CommandRunner;
use Bulbula\Backend\Console\Output;
use Bulbula\Backend\Core\Application;
use Bulbula\Tests\Support\ApplicationTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

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

    /** The repository root, so the test can count the migrations it ships. */
    private function basePath(): string
    {
        return dirname(__DIR__, 3);
    }

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

        self::assertSame(
            ['migrate', 'migration:status', 'rollback', 'reference:import', 'search:reindex', 'search:reindex:drift'],
            $names,
        );
    }

    #[Test]
    public function migrate_applies_every_shipped_migration_and_is_idempotent(): void
    {
        $first = $this->capture();

        self::assertSame(0, $this->console(['migrate'], $first));
        self::assertStringContainsString('2026_10_11_120000_create_audit_entries.php', $first->stdout);
        self::assertStringContainsString('2026_10_11_130000_create_customers.php', $first->stdout);

        // The count is asserted against the files on disk, not against a
        // number in this test, so adding a migration does not break it.
        $shipped = count(glob($this->basePath() . '/database/migrations/*.php') ?: []);

        self::assertStringContainsString($shipped . ' migration(s) applied', $first->stdout);
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

        $shipped = count(glob($this->basePath() . '/database/migrations/*.php') ?: []);

        self::assertSame(0, $this->console(['rollback'], $output));
        // Everything applied in one batch reverts as one batch, newest first.
        self::assertStringContainsString('reverted 2026_10_13_120100_create_search_documents.php', $output->stdout);
        self::assertStringContainsString('reverted 2026_10_11_120000_create_audit_entries.php', $output->stdout);
        self::assertStringContainsString($shipped . ' migration(s) reverted', $output->stdout);

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

    #[Test]
    public function a_command_that_fails_says_so_and_returns_non_zero(): void
    {
        // A console that prints nothing is indistinguishable from a console that
        // succeeded: an operator concludes "there was nothing to do" while the
        // schema they believe in does not exist. The runner must speak.
        $output = new class () implements Output {
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

        $runner = new CommandRunner([
            'explode' => static fn (): Command => new class () implements Command {
                public function name(): string
                {
                    return 'explode';
                }

                public function description(): string
                {
                    return 'Always fails.';
                }

                /** @param list<string> $arguments */
                public function run(array $arguments): int
                {
                    throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
                }
            },
        ], $output);

        self::assertSame(1, $runner->run(['bin/console', 'explode']));
        self::assertStringContainsString('SQLSTATE[HY000] [2002] Connection refused', $output->stderr);
        self::assertStringContainsString('explode', $output->stderr);
        self::assertSame('', $output->stdout);
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
