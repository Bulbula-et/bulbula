<?php

declare(strict_types=1);

namespace Bulbula\Backend\Database;

use Bulbula\Backend\Core\Config;
use PDO;

/**
 * The only place a PDO instance is created.
 *
 * Two drivers are supported: MariaDB for the application, SQLite in memory for
 * tests. Both use the same code path, so a test exercises the real queries.
 */
final class ConnectionFactory
{
    public static function create(Config $config): PDO
    {
        $driver = $config->string('database.driver', 'mysql');

        return $driver === 'sqlite'
            ? self::sqlite($config)
            : self::mysql($config);
    }

    private static function mysql(Config $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config->string('database.host', '127.0.0.1'),
            $config->int('database.port', 3306),
            $config->string('database.database', ''),
            $config->string('database.charset', 'utf8mb4'),
        );

        return new PDO(
            $dsn,
            $config->string('database.username'),
            $config->string('database.password'),
            self::options() + [
                // Real prepared statements: escaping is the server's job, not
                // ours. This option is MySQL-only, so it is set only here.
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private static function sqlite(Config $config): PDO
    {
        $path = $config->string('database.sqlite_path', ':memory:');

        return new PDO(
            'sqlite:' . $path,
            null,
            null,
            self::options(),
        );
    }

    /** @return array<int, mixed> */
    private static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];
    }
}
