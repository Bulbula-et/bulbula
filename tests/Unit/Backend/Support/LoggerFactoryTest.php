<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Support;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Exceptions\ConfigurationException;
use Bulbula\Backend\Support\LoggerFactory;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The logger is built at boot, from configuration, and its behaviour when the
 * configuration or the filesystem is wrong is part of the design: an
 * unrecognised level is tolerated, an unwritable path is not.
 */
final class LoggerFactoryTest extends TestCase
{
    private string $basePath = '';

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/bulbula-logging-' . bin2hex(random_bytes(4));

        mkdir($this->basePath . '/config', 0777, true);
        mkdir($this->basePath . '/storage/logs', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/storage/logs/app.log', '/config/logging.php', '/config/app.php'] as $file) {
            if (is_file($this->basePath . $file)) {
                unlink($this->basePath . $file);
            }
        }

        @rmdir($this->basePath . '/storage/logs');
        @rmdir($this->basePath . '/storage');
        @rmdir($this->basePath . '/config');
        @rmdir($this->basePath);
    }

    #[Test]
    public function it_uses_the_configured_level(): void
    {
        $logger = $this->logger(['level' => 'debug', 'json' => 'false']);

        self::assertInstanceOf(Logger::class, $logger);
        self::assertSame(Level::Debug, $logger->getHandlers()[0]->getLevel());
    }

    #[Test]
    public function an_unrecognised_level_falls_back_to_warning(): void
    {
        // A typo in .env must not take the application down.
        $logger = $this->logger(['level' => 'verbose', 'json' => 'false']);

        self::assertSame(Level::Warning, $logger->getHandlers()[0]->getLevel());
    }

    #[Test]
    public function it_writes_to_the_configured_path(): void
    {
        $logger = $this->logger(['level' => 'debug', 'json' => 'true']);

        $logger->warning('Readiness failed.', ['check' => 'database']);

        $written = file_get_contents($this->basePath . '/storage/logs/app.log');

        self::assertIsString($written);
        self::assertStringContainsString('Readiness failed.', $written);
        self::assertStringContainsString('"check":"database"', $written);
    }

    #[Test]
    public function an_unwritable_log_path_fails_at_boot_not_at_first_write(): void
    {
        // Monolog opens its stream lazily: unchecked, this would surface on the
        // first log line instead of here, during the incident it should record.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('is not writable');

        $this->logger(['level' => 'debug', 'json' => 'false'], '/nonexistent-directory/app.log');
    }

    /** @param array<string, string> $logging */
    private function logger(array $logging, string $path = 'storage/logs/app.log'): LoggerInterface
    {
        $this->writeConfig('logging', array_merge(['path' => $path], $logging));
        $this->writeConfig('app', ['name' => 'Bulbula']);

        $config = new Config($this->basePath);
        $config->load('app', 'logging');

        return LoggerFactory::create($config, $this->basePath);
    }

    /** @param array<string, string> $values */
    private function writeConfig(string $name, array $values): void
    {
        file_put_contents(
            $this->basePath . '/config/' . $name . '.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($values, true) . ";\n",
        );
    }
}
