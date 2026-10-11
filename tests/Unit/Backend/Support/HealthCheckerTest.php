<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Support;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Support\Health\HealthChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HealthCheckerTest extends TestCase
{
    private string $basePath = '';

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/bulbula-health-' . bin2hex(random_bytes(4));
        mkdir($this->basePath . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->basePath . '/config/database.php');
        @rmdir($this->basePath . '/config');
        @rmdir($this->basePath);
    }

    #[Test]
    public function readiness_passes_when_every_dependency_answers(): void
    {
        $storage = sys_get_temp_dir();

        $report = $this->checker(':memory:', $storage)->readiness();

        self::assertTrue($report->passes());
        self::assertSame(['database' => 'ok', 'storage' => 'ok'], $report->checks);
        self::assertSame('pass', $report->toArray()['status']);
    }

    #[Test]
    public function readiness_names_the_database_when_it_cannot_be_reached(): void
    {
        $storage = sys_get_temp_dir();

        $report = $this->checker('/nonexistent-directory/bulbula.sqlite', $storage)->readiness();

        self::assertFalse($report->passes());
        self::assertSame('error', $report->checks['database']);
        self::assertSame('ok', $report->checks['storage']);
        self::assertSame('fail', $report->toArray()['status']);
    }

    #[Test]
    public function readiness_names_storage_when_it_is_not_writable(): void
    {
        $report = $this->checker(':memory:', '/nonexistent-directory')->readiness();

        self::assertFalse($report->passes());
        self::assertSame('ok', $report->checks['database']);
        self::assertSame('error', $report->checks['storage']);
    }

    #[Test]
    public function liveness_never_depends_on_a_dependency(): void
    {
        // A database that cannot be reached must not make a healthy process
        // look dead: that is what turns a blip into a restart loop.
        $checker = $this->checker('/nonexistent-directory/bulbula.sqlite', '/nonexistent-directory');

        self::assertTrue($checker->liveness());
        self::assertFalse($checker->readiness()->passes());
    }

    /**
     * `$sqlitePath` is the configured path, not a DSN: the factory prefixes
     * `sqlite:`. Passing a whole DSN here makes SQLite create a file called
     * `sqlite::memory:` in the working directory.
     */
    private function checker(string $sqlitePath, string $storagePath): HealthChecker
    {
        file_put_contents(
            $this->basePath . '/config/database.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn ['driver' => 'sqlite', 'sqlite_path' => " . var_export($sqlitePath, true) . "];\n",
        );

        $config = new Config($this->basePath);
        $config->load('database');

        return new HealthChecker(new Connection($config), $storagePath);
    }
}
