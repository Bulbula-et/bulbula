<?php

declare(strict_types=1);

/**
 * Sub-cities — the administrative unit above an Area (data-model §3.6, GEO-6).
 *
 * The model's full hierarchy is Country → City/Region → Sub-city → Area
 * (GEO-1); it is populated one branch deep for launch (GEO-5), so the city
 * lives on this row as a name and no city/region table is created yet.
 *
 * Reference data, never application schema: adding a Sub-city is an insert
 * (GEO-4, C-24) — no code change, no migration.
 */
return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE sub_cities ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'slug TEXT NOT NULL UNIQUE, '
                . 'name TEXT NOT NULL, '
                . 'name_am TEXT NULL, '
                . 'city TEXT NOT NULL, '
                . 'created_at TEXT NOT NULL, '
                . 'updated_at TEXT NOT NULL'
                . ')',
            );

            return;
        }

        $pdo->exec(
            'CREATE TABLE sub_cities ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'slug VARCHAR(80) NOT NULL, '
            . 'name VARCHAR(120) NOT NULL, '
            . 'name_am VARCHAR(120) NULL, '
            . 'city VARCHAR(120) NOT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'updated_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (id), '
            . 'UNIQUE KEY sub_cities_slug (slug)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS sub_cities');
    }
};
