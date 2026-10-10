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
    public static function load(string $basePath): void
    {
        if (is_file($basePath . '/.env')) {
            Dotenv::createImmutable($basePath)->safeLoad();
        }
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
