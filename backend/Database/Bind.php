<?php

declare(strict_types=1);

namespace Bulbula\Backend\Database;

use PDO;
use PDOStatement;

/**
 * Executes a prepared statement with parameter types preserved.
 *
 * PDO binds an `execute()` array as strings, and an aggregate comparison —
 * `COUNT(DISTINCT token) = :n` — then compares an INTEGER against TEXT '2'
 * and is **never equal**: a column comparison coerces by affinity, an
 * expression comparison does not. A count that silently returns nothing is
 * the worst kind of bug: no error, just an empty result that looks like "no
 * matches". So every parameter arrives with its real type.
 */
final class Bind
{
    /** @param array<array-key, mixed> $parameters */
    public static function typed(PDOStatement $statement, array $parameters): void
    {
        foreach ($parameters as $name => $value) {
            $type = match (true) {
                is_bool($value) => PDO::PARAM_INT,
                is_int($value)  => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };

            $statement->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
        }

        $statement->execute();
    }
}
