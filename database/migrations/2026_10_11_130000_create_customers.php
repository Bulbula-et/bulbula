<?php

declare(strict_types=1);

/**
 * Customers (data-model §5.1, C-30, C-31).
 *
 * Notes that matter:
 *
 * - **No password column.** Not "not yet" — none, ever (AI-2, D-48).
 * - `email` is unique and `email_verified_at` is set at creation, because a
 *   Customer is only ever created once an address is verified (NA-1).
 * - `public_ref` exists because an identifier sometimes has to leave the
 *   system; a sequential id would publish how many Customers exist.
 */
return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE customers ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'public_ref TEXT NOT NULL UNIQUE, '
                . 'email TEXT NOT NULL UNIQUE, '
                . 'email_verified_at TEXT NULL, '
                . 'display_name TEXT NULL, '
                . 'state TEXT NOT NULL, '
                . 'created_at TEXT NOT NULL, '
                . 'updated_at TEXT NOT NULL, '
                . 'last_seen_at TEXT NULL'
                . ')',
            );

            return;
        }

        $pdo->exec(
            'CREATE TABLE customers ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'public_ref VARCHAR(64) NOT NULL, '
            . 'email VARCHAR(191) NOT NULL, '
            . 'email_verified_at DATETIME NULL, '
            . 'display_name VARCHAR(80) NULL, '
            . 'state VARCHAR(16) NOT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'updated_at DATETIME NOT NULL, '
            . 'last_seen_at DATETIME NULL, '
            . 'PRIMARY KEY (id), '
            . 'UNIQUE KEY customers_email (email), '
            . 'UNIQUE KEY customers_public_ref (public_ref)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS customers');
    }
};
