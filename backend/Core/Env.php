<?php

declare(strict_types=1);

namespace Bulbula\Backend\Core;

use Dotenv\Dotenv;

/**
 * The single reader of the environment.
 *
 * Everything else in the application reads configuration through Config.
 * Keeping the environment in one place means a review of "where does this
 * value come from?" has exactly one answer.
 */
final class Env
{
    private static bool $fileFound = false;

    /**
     * Read `.env` if it is there.
     *
     * `safeLoad()` is deliberate: a project with no `.env` is a legitimate
     * state (the test suite, a host configured entirely by environment
     * variables), and every value has a safe default. But "no file" must not be
     * *invisible* — see `fileFound()`. `.env.example` is never read as
     * configuration: the example is a document, and treating it as settings
     * would mean shipping a host the file it was told to copy from.
     */
    public static function load(string $basePath): void
    {
        self::$fileFound = is_file($basePath . '/.env');

        if (self::$fileFound) {
            Dotenv::createImmutable($basePath)->safeLoad();
        }
    }

    /** Whether `.env` was present where the application looked for it. */
    public static function fileFound(): bool
    {
        return self::$fileFound;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }
}
