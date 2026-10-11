<?php

declare(strict_types=1);

namespace Bulbula\Backend\Database;

use UnexpectedValueException;

/**
 * Reads a column out of a fetched row, with the type it must have.
 *
 * A driver hands back `mixed`, and casting it blindly is how a string silently
 * becomes the integer 0 — a session belonging to principal 0, a timestamp of
 * 1970. Narrowing here means a database whose shape is not what the code
 * expects fails at the read, in one place, naming the column.
 *
 * Values are the ones the two engines actually return: strings and integers.
 * A `DECIMAL` or a driver returning something else is a deliberate failure,
 * not a coercion.
 */
final class Row
{
    /** @param array<array-key, mixed> $row */
    public static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? throw self::missing($row, $column);

        if (!is_string($value)) {
            throw self::unexpected($column, 'string', $value);
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    public static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? throw self::missing($row, $column);

        if (is_int($value)) {
            return $value;
        }

        // Both engines return integer columns as PHP strings in some builds;
        // a numeric string is still an integer, anything else is not.
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw self::unexpected($column, 'integer', $value);
    }

    /** @param array<array-key, mixed> $row */
    public static function nullableString(array $row, string $column): ?string
    {
        // `array_key_exists`, not `??`: a NULL column is a value here, and a
        // missing column is a different thing entirely.
        if (!array_key_exists($column, $row)) {
            throw self::missing($row, $column);
        }

        $value = $row[$column];

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw self::unexpected($column, 'string or null', $value);
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    private static function missing(array $row, string $column): UnexpectedValueException
    {
        return new UnexpectedValueException(
            'The row has no column [' . $column . ']; it holds [' . implode(', ', array_keys($row)) . '].',
        );
    }

    private static function unexpected(string $column, string $expected, mixed $actual): UnexpectedValueException
    {
        return new UnexpectedValueException(
            'Column [' . $column . '] should be ' . $expected . ', got ' . get_debug_type($actual) . '.',
        );
    }
}
