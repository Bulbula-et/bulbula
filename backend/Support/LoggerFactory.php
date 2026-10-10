<?php

declare(strict_types=1);

namespace Bulbula\Backend\Support;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Exceptions\ConfigurationException;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * One logger, configured from config/logging.php.
 *
 * JSON lines in production so a log shipper can read them; readable lines
 * everywhere else. Credentials, tokens, session ids and OTP codes must never
 * reach a log line — see the security rules in the README.
 *
 * If storage/logs is not writable this throws at boot. That is deliberate: a
 * system that cannot record what it did should not pretend to run.
 */
final class LoggerFactory
{
    public static function create(Config $config, string $basePath): LoggerInterface
    {
        $path  = $basePath . '/' . ltrim($config->string('logging.path', 'storage/logs/app.log'), '/');
        $level = self::level($config->string('logging.level', 'warning'));

        self::assertWritable($path);

        $handler = new StreamHandler($path, $level);
        $handler->setFormatter(
            $config->bool('logging.json')
                ? new JsonFormatter()
                : new LineFormatter("%datetime% %level_name% %message% %context%\n", 'Y-m-d H:i:s'),
        );

        return new Logger($config->string('app.name', 'Bulbula'), [$handler]);
    }

    /**
     * Monolog opens its stream lazily, so an unwritable path would be discovered
     * on the first log line — which is to say, during the incident it was meant
     * to record. It is checked here, where the failure is still a configuration
     * failure with a readable message.
     *
     * @throws ConfigurationException when the log target cannot be written
     */
    private static function assertWritable(string $path): void
    {
        $target = is_file($path) ? $path : dirname($path);

        if (!is_writable($target)) {
            throw new ConfigurationException(
                'The log target [' . $path . '] is not writable. A system that cannot record what it did should not run.',
            );
        }
    }

    /**
     * An unrecognised level falls back to warning rather than throwing: a typo
     * in `.env` must not take the application down. The mapping is written out
     * rather than delegated to Monolog's `fromName()`, which matches without a
     * default and would raise `UnhandledMatchError` on that typo. The names are
     * PSR-3's, which is what a person writing `.env` will reach for.
     */
    private static function level(string $name): Level
    {
        return match (strtolower(trim($name))) {
            'debug'     => Level::Debug,
            'info'      => Level::Info,
            'notice'    => Level::Notice,
            'warning'   => Level::Warning,
            'error'     => Level::Error,
            'critical'  => Level::Critical,
            'alert'     => Level::Alert,
            'emergency' => Level::Emergency,
            default     => Level::Warning,
        };
    }
}
