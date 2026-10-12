<?php

declare(strict_types=1);

/**
 * Categories — the Bulbula-owned taxonomy, exactly two levels (data-model §3.4, D-06).
 *
 * One self-referencing table models both levels: `parent_id` is null for a
 * Category and set for a Subcategory. Two levels are the decision (D-06), so
 * no depth column and no recursion exist to guard a third level that must not
 * be created.
 *
 * The catalogue is **reference data, not application schema** (D-56): the rows
 * are curated as an operations task and no code change is required to add,
 * rename or reclassify one (TR-217). English is required, Amharic optional,
 * and `visible` hides an entry from navigation **without** unclassifying the
 * Listings beneath it (C-23) — which is exactly why visibility is a flag here
 * and not a delete.
 */
return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE categories ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'parent_id INTEGER NULL REFERENCES categories (id), '
                . 'slug TEXT NOT NULL, '
                . 'name TEXT NOT NULL, '
                . 'name_am TEXT NULL, '
                . 'display_order INTEGER NOT NULL DEFAULT 0, '
                . 'visible INTEGER NOT NULL DEFAULT 1, '
                . 'icon TEXT NULL, '
                . 'created_at TEXT NOT NULL, '
                . 'updated_at TEXT NOT NULL'
                . ')',
            );

            return;
        }

        $pdo->exec(
            'CREATE TABLE categories ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'parent_id BIGINT UNSIGNED NULL, '
            . 'slug VARCHAR(80) NOT NULL, '
            . 'name VARCHAR(120) NOT NULL, '
            . 'name_am VARCHAR(120) NULL, '
            . 'display_order INT NOT NULL DEFAULT 0, '
            . 'visible TINYINT(1) NOT NULL DEFAULT 1, '
            . 'icon VARCHAR(64) NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'updated_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (id), '
            . 'KEY categories_parent (parent_id), '
            . 'CONSTRAINT categories_parent_fk FOREIGN KEY (parent_id) REFERENCES categories (id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS categories');
    }
};
