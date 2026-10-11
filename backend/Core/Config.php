<?php

declare(strict_types=1);

namespace Bulbula\Backend\Core;

use Bulbula\Backend\Exceptions\ConfigurationException;

/**
 * Immutable, dot-notation access to config/*.php.
 *
 * A missing key returns the caller's default rather than throwing, except
 * where a value is genuinely required — use require() for those so a
 * misconfigured install fails at boot instead of at 3am.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * Load config files by name. A file's values are nested under its own
     * name, so config/app.php is read as app.*, config/database.php as
     * database.* — the file name is the namespace, and two files can never
     * collide on a key.
     */
    public function load(string ...$files): void
    {
        foreach ($files as $file) {
            $path = $this->basePath . '/config/' . $file . '.php';

            if (!is_file($path)) {
                continue;
            }

            $values = require $path;

            if (!is_array($values)) {
                throw new ConfigurationException('config/' . $file . '.php must return an array.');
            }

            /** @var array<string, mixed> $current */
            $current = $this->items[$file] ?? [];

            $this->items[$file] = array_replace_recursive($current, $values);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function require(string $key): mixed
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            throw new ConfigurationException('Required configuration [' . $key . '] is missing. Check .env against .env.example.');
        }

        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : $default;
    }
}
