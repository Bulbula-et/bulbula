<?php

declare(strict_types=1);

/**
 * Businesses — the brand-level entity (data-model §3.1, D-55).
 *
 * The states are `draft → in_review → published → (unpublished | closed)`,
 * and **closure is a state, never a delete** (TR-53): the row, its URL and its
 * history survive, which is why `closed_at` and `closed_reason` exist beside
 * `unpublished_at` rather than a `deleted_at` — a closure that removed the
 * row would take the audit trail's subject with it.
 *
 * `version` is the optimistic-concurrency token (TR-57): an update asserts the
 * version it read and fails loudly on a mismatch, because silently overwriting
 * a concurrent edit is how two operators lose one of their changes.
 *
 * The ratings columns of §3.1 are deliberately **not** here yet: they are
 * derived from Reviews (TR-62/63) and arrive with that slice, together with
 * the writer that keeps them rebuildable. `completeness` is absent for the
 * same reason — its definition is Open (D-09).
 *
 * Name + primary Area is intentionally not unique: genuinely similar
 * businesses exist. The pair is the duplicate-detection signal surfaced at
 * creation time (TR-55), not a constraint.
 */
return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE businesses ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'slug TEXT NOT NULL UNIQUE, '
                . 'name TEXT NOT NULL, '
                . 'legal_name TEXT NULL, '
                . 'name_am TEXT NULL, '
                . 'short_description TEXT NULL, '
                . 'long_description TEXT NULL, '
                . 'website TEXT NULL, '
                . 'email TEXT NULL, '
                . 'social_links TEXT NULL, '
                . 'status TEXT NOT NULL, '
                . 'published_at TEXT NULL, '
                . 'unpublished_at TEXT NULL, '
                . 'closed_at TEXT NULL, '
                . 'closure_reason TEXT NULL, '
                . 'version INTEGER NOT NULL DEFAULT 1, '
                . 'created_by INTEGER NULL, '
                . 'created_at TEXT NOT NULL, '
                . 'updated_by INTEGER NULL, '
                . 'updated_at TEXT NOT NULL'
                . ')',
            );

            return;
        }

        $pdo->exec(
            'CREATE TABLE businesses ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'slug VARCHAR(80) NOT NULL, '
            . 'name VARCHAR(160) NOT NULL, '
            . 'legal_name VARCHAR(160) NULL, '
            . 'name_am VARCHAR(160) NULL, '
            . 'short_description VARCHAR(280) NULL, '
            . 'long_description TEXT NULL, '
            . 'website VARCHAR(255) NULL, '
            . 'email VARCHAR(191) NULL, '
            . 'social_links TEXT NULL, '
            . 'status VARCHAR(16) NOT NULL, '
            . 'published_at DATETIME NULL, '
            . 'unpublished_at DATETIME NULL, '
            . 'closed_at DATETIME NULL, '
            . 'closure_reason VARCHAR(255) NULL, '
            . 'version INT UNSIGNED NOT NULL DEFAULT 1, '
            . 'created_by BIGINT UNSIGNED NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'updated_by BIGINT UNSIGNED NULL, '
            . 'updated_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (id), '
            . 'UNIQUE KEY businesses_slug (slug)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS businesses');
    }
};
