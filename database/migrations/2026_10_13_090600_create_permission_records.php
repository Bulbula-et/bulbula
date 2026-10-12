<?php

declare(strict_types=1);

/**
 * Permission records — the evidence that a Business agreed to collection and
 * publication (data-model §4.1, D-50).
 *
 * **No publication without a linked Permission record** (TR-49), from every
 * path including import — which is why the record is its own table with its
 * own life: a grant is withdrawn by state, never deleted, and a withdrawal
 * must be able to unpublish promptly (PRD TS-5). The publication gate reads
 * *this* row, so the invariant cannot be routed around by a caller that
 * forgets to check a flag.
 *
 * Personal data is minimised to the agreeing person's name and claimed role
 * (D-51); `evidence_reference` is an optional pointer whose exact contents are
 * still Open (D-43) — the column stores where an artefact lives, never the
 * artefact.
 */
return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE permission_records ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'business_id INTEGER NOT NULL REFERENCES businesses (id), '
                . 'person_name TEXT NOT NULL, '
                . 'person_role TEXT NULL, '
                . 'method TEXT NOT NULL, '
                . 'granted_at TEXT NOT NULL, '
                . 'obtained_by INTEGER NULL, '
                . 'scope_note TEXT NULL, '
                . 'state TEXT NOT NULL, '
                . 'withdrawn_at TEXT NULL, '
                . 'withdrawal_reason TEXT NULL, '
                . 'evidence_reference TEXT NULL, '
                . 'created_at TEXT NOT NULL'
                . ')',
            );

            return;
        }

        $pdo->exec(
            'CREATE TABLE permission_records ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'business_id BIGINT UNSIGNED NOT NULL, '
            . 'person_name VARCHAR(160) NOT NULL, '
            . 'person_role VARCHAR(120) NULL, '
            . 'method VARCHAR(24) NOT NULL, '
            . 'granted_at DATETIME NOT NULL, '
            . 'obtained_by BIGINT UNSIGNED NULL, '
            . 'scope_note VARCHAR(255) NULL, '
            . 'state VARCHAR(16) NOT NULL, '
            . 'withdrawn_at DATETIME NULL, '
            . 'withdrawal_reason VARCHAR(255) NULL, '
            . 'evidence_reference VARCHAR(255) NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (id), '
            . 'KEY permission_records_business (business_id), '
            . 'CONSTRAINT permission_records_business_fk FOREIGN KEY (business_id) REFERENCES businesses (id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS permission_records');
    }
};
