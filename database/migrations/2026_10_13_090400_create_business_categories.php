<?php

declare(strict_types=1);

/**
 * `business_categories` — classification, with the D-57 cardinality.
 *
 * Every Listing carries **exactly one primary Category** and **zero or more
 * secondary Categories**. This join table with an `is_primary` flag models
 * that directly (data-model §3.4):
 *
 * - `UNIQUE (business_id, category_id)` makes a Category non-repeatable for
 *   one Listing — which simultaneously forbids the primary also appearing as
 *   a secondary;
 * - the *exactly one primary* rule is a **publication-time check plus a
 *   database constraint where the engine supports one**. SQLite and MySQL do
 *   not offer a portable partial unique index, so the publication gate
 *   (`PublicationGate`) refuses a Listing with zero or several primaries, and
 *   the service refuses to record a second one. The constraint that can be
 *   expressed portably is in the schema; the rest is enforced where the
 *   invariant is read, and both are tested.
 *
 * There is no artificial numeric maximum on secondary Categories (D-57).
 */
return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE business_categories ('
                . 'business_id INTEGER NOT NULL REFERENCES businesses (id), '
                . 'category_id INTEGER NOT NULL REFERENCES categories (id), '
                . 'is_primary INTEGER NOT NULL DEFAULT 0, '
                . 'created_at TEXT NOT NULL, '
                . 'PRIMARY KEY (business_id, category_id)'
                . ')',
            );

            return;
        }

        $pdo->exec(
            'CREATE TABLE business_categories ('
            . 'business_id BIGINT UNSIGNED NOT NULL, '
            . 'category_id BIGINT UNSIGNED NOT NULL, '
            . 'is_primary TINYINT(1) NOT NULL DEFAULT 0, '
            . 'created_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (business_id, category_id), '
            . 'KEY business_categories_category (category_id), '
            . 'CONSTRAINT business_categories_business_fk FOREIGN KEY (business_id) REFERENCES businesses (id), '
            . 'CONSTRAINT business_categories_category_fk FOREIGN KEY (category_id) REFERENCES categories (id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS business_categories');
    }
};
