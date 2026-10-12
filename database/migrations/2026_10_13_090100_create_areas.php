<?php

declare(strict_types=1);

/**
 * Areas — the user-facing location unit (data-model §3.6).
 *
 * A Branch **requires** an Area and its Sub-city (GEO-6). The slug is the URL
 * unit (`/a/{area}`, `information-architecture-v1.0.md` §2.1) and is unique;
 * Amharic naming is stored from the start so the grammar never bakes in an
 * English-only assumption (IAR-6, D-18).
 *
 * Deletion is refused while Branches are assigned (C-24); `visible` hides an
 * Area from navigation without unassigning anything.
 */
return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE areas ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'sub_city_id INTEGER NOT NULL REFERENCES sub_cities (id), '
                . 'slug TEXT NOT NULL UNIQUE, '
                . 'name TEXT NOT NULL, '
                . 'name_am TEXT NULL, '
                . 'display_order INTEGER NOT NULL DEFAULT 0, '
                . 'visible INTEGER NOT NULL DEFAULT 1, '
                . 'created_at TEXT NOT NULL, '
                . 'updated_at TEXT NOT NULL'
                . ')',
            );

            return;
        }

        $pdo->exec(
            'CREATE TABLE areas ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'sub_city_id BIGINT UNSIGNED NOT NULL, '
            . 'slug VARCHAR(80) NOT NULL, '
            . 'name VARCHAR(120) NOT NULL, '
            . 'name_am VARCHAR(120) NULL, '
            . 'display_order INT NOT NULL DEFAULT 0, '
            . 'visible TINYINT(1) NOT NULL DEFAULT 1, '
            . 'created_at DATETIME NOT NULL, '
            . 'updated_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (id), '
            . 'UNIQUE KEY areas_slug (slug), '
            . 'KEY areas_sub_city (sub_city_id), '
            . 'CONSTRAINT areas_sub_city_fk FOREIGN KEY (sub_city_id) REFERENCES sub_cities (id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS areas');
    }
};
