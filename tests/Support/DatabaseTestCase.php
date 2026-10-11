<?php

declare(strict_types=1);

namespace Bulbula\Tests\Support;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\Migrations\MigrationLocator;
use Bulbula\Backend\Database\Migrations\MigrationRepository;
use Bulbula\Backend\Database\Migrations\Migrator;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * A migrated database in memory, a clock the test controls, and the real
 * security configuration.
 *
 * The values are the shipped ones, read from `config/security.php` rather than
 * invented in the test, because the numbers a limit is enforced with are part
 * of what is being tested — a test with its own limits would pass while
 * production refused everybody. A test that needs a different policy overrides
 * the specific key it cares about, so the override is visible in the test that
 * depends on it.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected TestClock $clock;

    private ?Config $config = null;

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new TestClock(
            new DateTimeImmutable('2026-10-11 09:00:00', new DateTimeZone('UTC')),
        );
    }

    /** The migrated database, shared by the code under test and the assertions. */
    protected function database(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $basePath = sys_get_temp_dir() . '/bulbula-db-' . bin2hex(random_bytes(4));
        mkdir($basePath . '/config', 0777, true);

        file_put_contents(
            $basePath . '/config/database.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn ['driver' => 'sqlite', 'sqlite_path' => ':memory:'];\n",
        );

        $this->writeSecurityConfig($basePath);

        $config = new Config($basePath);
        $config->load('database', 'security');
        $this->config = $config;

        $connection = new Connection($config);

        $migrator = new Migrator(
            $connection,
            new MigrationLocator($this->basePath() . '/database/migrations'),
            new MigrationRepository($connection),
            $this->clock,
        );
        $migrator->apply();

        return $this->connection = $connection;
    }

    /**
     * Security values a test needs to differ from the shipped ones, as
     * `['session.absolute_lifetime' => 3600, …]`. Empty by default: nothing is
     * overridden unless a test says why.
     *
     * @return array<string, int|string|bool>
     */
    protected function securityOverrides(): array
    {
        return [];
    }

    /** The shipped values, with this test's overrides applied on top. */
    private function writeSecurityConfig(string $basePath): void
    {
        /** @var array<string, mixed> $security */
        $security = require $this->basePath() . '/config/security.php';

        foreach ($this->securityOverrides() as $path => $value) {
            $segments = explode('.', $path);
            $cursor = &$security;

            foreach ($segments as $segment) {
                if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                    throw new LogicException('No security config key [' . $path . '] to override.');
                }

                $cursor = &$cursor[$segment];
            }

            $cursor = $value;
        }

        file_put_contents(
            $basePath . '/config/security.php',
            '<?php

declare(strict_types=1);

return ' . var_export($security, true) . ';
',
        );
    }

    /** The repository root, so a test never counts directory levels by hand. */
    protected function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function config(): Config
    {
        $this->database();

        return $this->config ?? throw new LogicException('The test database was not configured.');
    }
}
