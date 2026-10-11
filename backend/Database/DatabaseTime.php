<?php

declare(strict_types=1);

namespace Bulbula\Backend\Database;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The one place a `DATETIME` column turns into an instant and back.
 *
 * Both engines accept `Y-m-d H:i:s` and nothing about that format is
 * interesting — but *assuming* it in six repositories is how a storage detail
 * leaks into the domain. Timestamps are written and read in UTC (TR-05); a
 * value that will not parse is a bug, not a `null`.
 */
final class DatabaseTime
{
    public const FORMAT = 'Y-m-d H:i:s';

    public static function format(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(self::utc())->format(self::FORMAT);
    }

    public static function parse(?string $stored): ?DateTimeImmutable
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(self::FORMAT, $stored, self::utc());

        if ($parsed === false) {
            throw new \UnexpectedValueException('Stored timestamp [' . $stored . '] is not in ' . self::FORMAT . '.');
        }

        return $parsed;
    }

    private static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}
