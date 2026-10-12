<?php

declare(strict_types=1);

/**
 * Branches — a physical location of a Business (data-model §3.2, D-03).
 *
 * **Every Business has at least one Branch**; a single-location business has
 * exactly one and is never a special case (PRD BM-1, BM-2). One Branch per
 * Business is primary, and the model says exactly one is — the check lives in
 * `BranchRepository`/`PublicationGate` because neither engine offers a
 * portable partial unique index for it.
 *
 * The Business/Branch attribute boundary is fixed (D-55, DM-R1): address area,
 * sub-city, coordinates, phones, email and the optional label are **branch**
 * attributes, and nothing here may be moved up to Business for convenience.
 * Area and Sub-city are both required (GEO-6) even though the URL hierarchy
 * does not expose Sub-city in V1 — administrative correctness is not a
 * navigation decision.
 *
 * `slug_segment` is optional and unused until the multi-branch profile view
 * needs one (§3.2); it is not derived from anything, so it cannot drift.
 *
 * Landmark stays unmodelled in this slice: it is a named entity attached to a
 * Branch **or an Area** (§3.7), and modelling it as branch free text would be
 * a different design. It arrives with the entity, as its own migration.
 */
return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE branches ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'business_id INTEGER NOT NULL REFERENCES businesses (id), '
                . 'area_id INTEGER NOT NULL REFERENCES areas (id), '
                . 'sub_city_id INTEGER NOT NULL REFERENCES sub_cities (id), '
                . 'label TEXT NULL, '
                . 'slug_segment TEXT NULL, '
                . 'address_line TEXT NULL, '
                . 'latitude REAL NULL, '
                . 'longitude REAL NULL, '
                . 'phone TEXT NULL, '
                . 'phone_secondary TEXT NULL, '
                . 'email TEXT NULL, '
                . 'status TEXT NOT NULL, '
                . 'is_primary INTEGER NOT NULL DEFAULT 0, '
                . 'created_at TEXT NOT NULL, '
                . 'updated_at TEXT NOT NULL'
                . ')',
            );

            return;
        }

        $pdo->exec(
            'CREATE TABLE branches ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'business_id BIGINT UNSIGNED NOT NULL, '
            . 'area_id BIGINT UNSIGNED NOT NULL, '
            . 'sub_city_id BIGINT UNSIGNED NOT NULL, '
            . 'label VARCHAR(80) NULL, '
            . 'slug_segment VARCHAR(80) NULL, '
            . 'address_line VARCHAR(255) NULL, '
            . 'latitude DECIMAL(9,6) NULL, '
            . 'longitude DECIMAL(9,6) NULL, '
            . 'phone VARCHAR(32) NULL, '
            . 'phone_secondary VARCHAR(32) NULL, '
            . 'email VARCHAR(191) NULL, '
            . 'status VARCHAR(24) NOT NULL, '
            . 'is_primary TINYINT(1) NOT NULL DEFAULT 0, '
            . 'created_at DATETIME NOT NULL, '
            . 'updated_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (id), '
            . 'KEY branches_business (business_id), '
            . 'KEY branches_area (area_id), '
            . 'CONSTRAINT branches_business_fk FOREIGN KEY (business_id) REFERENCES businesses (id), '
            . 'CONSTRAINT branches_area_fk FOREIGN KEY (area_id) REFERENCES areas (id), '
            . 'CONSTRAINT branches_sub_city_fk FOREIGN KEY (sub_city_id) REFERENCES sub_cities (id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS branches');
    }
};
