<?php

declare(strict_types=1);

/**
 * Rate-limit counters (TRD §34, OTP-6).
 *
 * One row per bucket and key: `bucket` names the policy in
 * `config/security.php` (`otp_request`, `review`, `search`, …) and `bucket_key`
 * is what the limit is applied to — an address, a network, an account.
 *
 * `bucket_key`, not `key`: `key` is a reserved word in MariaDB, and a schema
 * that only works with quoting is a schema that breaks the first time somebody
 * writes the query slightly differently.
 *
 * The window starts at `window_start` and is not a wall-clock period, so
 * "5 per 15 minutes" means five in any fifteen minutes from the first hit —
 * which is what a limit is for.
 */
return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE rate_limits ('
                . 'bucket TEXT NOT NULL, '
                . 'bucket_key TEXT NOT NULL, '
                . 'window_start TEXT NOT NULL, '
                . 'hits INTEGER NOT NULL, '
                . 'PRIMARY KEY (bucket, bucket_key)'
                . ')',
            );

            return;
        }

        $pdo->exec(
            'CREATE TABLE rate_limits ('
            . 'bucket VARCHAR(64) NOT NULL, '
            . 'bucket_key VARCHAR(191) NOT NULL, '
            . 'window_start DATETIME NOT NULL, '
            . 'hits INT UNSIGNED NOT NULL, '
            . 'PRIMARY KEY (bucket, bucket_key)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS rate_limits');
    }
};
