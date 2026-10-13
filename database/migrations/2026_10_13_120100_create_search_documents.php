<?php

declare(strict_types=1);

/**
 * The search document (data-model §8.5, search-design §3) — derived, rebuildable.
 *
 * Three tables, one purpose. `search_documents` holds one row per publicly
 * visible Business with its composed, normalised text and its filter columns.
 * `search_document_tokens` is the matching access path: one row per distinct
 * token, so every matching stage is an indexed equality or prefix range —
 * never a leading wildcard (MT-3) and never a server-side full-text engine
 * whose minimum token length and stopword list cannot be configured on shared
 * hosting (TR-196, IMR-12). `search_document_categories` and
 * `search_document_areas` carry the plural facets: a Listing has up to several
 * Categories (D-57) and a Business may trade in several Areas.
 *
 * Losing any of these tables loses nothing (SD-6): `search:reindex` rebuilds
 * them from the entity tables, which remain the source of truth (SD-1).
 */
return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE search_documents ('
                . 'business_id INTEGER PRIMARY KEY REFERENCES businesses (id), '
                . 'status TEXT NOT NULL, '
                . 'name_norm TEXT NOT NULL, '
                . 'name_text TEXT NOT NULL, '
                . 'classification_text TEXT NOT NULL, '
                . 'location_text TEXT NOT NULL, '
                . 'description_text TEXT NOT NULL, '
                . 'primary_category_id INTEGER NULL, '
                . 'area_id INTEGER NULL, '
                . 'sub_city_id INTEGER NULL, '
                . 'verified INTEGER NOT NULL DEFAULT 0, '
                . 'verified_at TEXT NULL, '
                . 'rating_avg REAL NULL, '
                . 'rating_count INTEGER NOT NULL DEFAULT 0, '
                . 'completeness INTEGER NULL, '
                . 'has_confirmed_hours INTEGER NOT NULL DEFAULT 0, '
                . 'latitude REAL NULL, '
                . 'longitude REAL NULL, '
                . 'created_at TEXT NOT NULL, '
                . 'updated_at TEXT NOT NULL'
                . ')',
            );
            $pdo->exec('CREATE INDEX search_documents_status ON search_documents (status)');
            $pdo->exec('CREATE INDEX search_documents_name ON search_documents (name_norm)');
            $pdo->exec('CREATE INDEX search_documents_category ON search_documents (primary_category_id)');
            $pdo->exec('CREATE INDEX search_documents_area ON search_documents (area_id)');
            $pdo->exec('CREATE INDEX search_documents_sub_city ON search_documents (sub_city_id)');
            $pdo->exec('CREATE INDEX search_documents_verified ON search_documents (verified)');
            $pdo->exec('CREATE INDEX search_documents_rating ON search_documents (rating_count, rating_avg)');
            $pdo->exec('CREATE INDEX search_documents_coords ON search_documents (latitude, longitude)');

            $pdo->exec(
                'CREATE TABLE search_document_tokens ('
                . 'business_id INTEGER NOT NULL REFERENCES businesses (id), '
                . 'token TEXT NOT NULL, '
                . 'in_name INTEGER NOT NULL DEFAULT 0, '
                . 'PRIMARY KEY (business_id, token)'
                . ')',
            );
            $pdo->exec('CREATE INDEX search_document_tokens_token ON search_document_tokens (token)');

            $pdo->exec(
                'CREATE TABLE search_document_categories ('
                . 'business_id INTEGER NOT NULL REFERENCES businesses (id), '
                . 'category_id INTEGER NOT NULL REFERENCES categories (id), '
                . 'is_primary INTEGER NOT NULL DEFAULT 0, '
                . 'PRIMARY KEY (business_id, category_id)'
                . ')',
            );
            $pdo->exec('CREATE INDEX search_document_categories_category ON search_document_categories (category_id)');

            $pdo->exec(
                'CREATE TABLE search_document_areas ('
                . 'business_id INTEGER NOT NULL REFERENCES businesses (id), '
                . 'area_id INTEGER NOT NULL REFERENCES areas (id), '
                . 'PRIMARY KEY (business_id, area_id)'
                . ')',
            );
            $pdo->exec('CREATE INDEX search_document_areas_area ON search_document_areas (area_id)');

            return;
        }

        $pdo->exec(
            'CREATE TABLE search_documents ('
            . 'business_id BIGINT UNSIGNED NOT NULL, '
            . 'status VARCHAR(16) NOT NULL, '
            . 'name_norm VARCHAR(191) NOT NULL, '
            . 'name_text TEXT NOT NULL, '
            . 'classification_text TEXT NOT NULL, '
            . 'location_text TEXT NOT NULL, '
            . 'description_text TEXT NOT NULL, '
            . 'primary_category_id BIGINT UNSIGNED NULL, '
            . 'area_id BIGINT UNSIGNED NULL, '
            . 'sub_city_id BIGINT UNSIGNED NULL, '
            . 'verified TINYINT(1) NOT NULL DEFAULT 0, '
            . 'verified_at DATETIME NULL, '
            . 'rating_avg DECIMAL(3,2) NULL, '
            . 'rating_count INT UNSIGNED NOT NULL DEFAULT 0, '
            . 'completeness INT UNSIGNED NULL, '
            . 'has_confirmed_hours TINYINT(1) NOT NULL DEFAULT 0, '
            . 'latitude DECIMAL(9,6) NULL, '
            . 'longitude DECIMAL(9,6) NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'updated_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (business_id), '
            . 'KEY search_documents_status (status), '
            . 'KEY search_documents_name (name_norm), '
            . 'KEY search_documents_category (primary_category_id), '
            . 'KEY search_documents_area (area_id), '
            . 'KEY search_documents_sub_city (sub_city_id), '
            . 'KEY search_documents_verified (verified), '
            . 'KEY search_documents_rating (rating_count, rating_avg), '
            . 'KEY search_documents_coords (latitude, longitude), '
            . 'CONSTRAINT search_documents_business_fk FOREIGN KEY (business_id) REFERENCES businesses (id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $pdo->exec(
            'CREATE TABLE search_document_tokens ('
            . 'business_id BIGINT UNSIGNED NOT NULL, '
            . 'token VARCHAR(191) NOT NULL, '
            . 'in_name TINYINT(1) NOT NULL DEFAULT 0, '
            . 'PRIMARY KEY (business_id, token), '
            . 'KEY search_document_tokens_token (token), '
            . 'CONSTRAINT search_document_tokens_business_fk FOREIGN KEY (business_id) REFERENCES businesses (id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $pdo->exec(
            'CREATE TABLE search_document_categories ('
            . 'business_id BIGINT UNSIGNED NOT NULL, '
            . 'category_id BIGINT UNSIGNED NOT NULL, '
            . 'is_primary TINYINT(1) NOT NULL DEFAULT 0, '
            . 'PRIMARY KEY (business_id, category_id), '
            . 'KEY search_document_categories_category (category_id), '
            . 'CONSTRAINT search_document_categories_business_fk FOREIGN KEY (business_id) REFERENCES businesses (id), '
            . 'CONSTRAINT search_document_categories_category_fk FOREIGN KEY (category_id) REFERENCES categories (id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $pdo->exec(
            'CREATE TABLE search_document_areas ('
            . 'business_id BIGINT UNSIGNED NOT NULL, '
            . 'area_id BIGINT UNSIGNED NOT NULL, '
            . 'PRIMARY KEY (business_id, area_id), '
            . 'KEY search_document_areas_area (area_id), '
            . 'CONSTRAINT search_document_areas_business_fk FOREIGN KEY (business_id) REFERENCES businesses (id), '
            . 'CONSTRAINT search_document_areas_area_fk FOREIGN KEY (area_id) REFERENCES areas (id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();
        $pdo->exec('DROP TABLE IF EXISTS search_document_areas');
        $pdo->exec('DROP TABLE IF EXISTS search_document_categories');
        $pdo->exec('DROP TABLE IF EXISTS search_document_tokens');
        $pdo->exec('DROP TABLE IF EXISTS search_documents');
    }
};
