<?php

declare(strict_types=1);

/**
 * Issued sign-in codes (data-model §5.3, C-31).
 *
 * - `code_hash` holds a **hash** and nothing else (OTP-2). There is no column
 *   a valid code could be read from.
 * - `session_binding` is what stops a code being driven from a different
 *   context than the one that asked for it (OTP-7).
 * - No foreign key to `customers`: a code is issued for an *address* before
 *   the Customer exists — the account is created by the verification, not
 *   before it (NA-1).
 * - The indexes are the ones the queries actually use: an address's recent
 *   codes, and a network's recent codes (OTP-6).
 *
 * Retention: these rows are pruned aggressively; the period is PENDING
 * COUNSEL (L-21) and is therefore not decided by this file.
 */
return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE otp_requests ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'email TEXT NOT NULL, '
                . 'code_hash TEXT NOT NULL, '
                . 'issued_at TEXT NOT NULL, '
                . 'expires_at TEXT NOT NULL, '
                . 'consumed_at TEXT NULL, '
                . 'attempts INTEGER NOT NULL DEFAULT 0, '
                . 'session_binding TEXT NULL, '
                . 'source_network TEXT NULL'
                . ')',
            );

            $pdo->exec('CREATE INDEX otp_requests_email ON otp_requests (email, issued_at)');
            $pdo->exec('CREATE INDEX otp_requests_network ON otp_requests (source_network, issued_at)');

            return;
        }

        $pdo->exec(
            'CREATE TABLE otp_requests ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'email VARCHAR(191) NOT NULL, '
            . 'code_hash VARCHAR(255) NOT NULL, '
            . 'issued_at DATETIME NOT NULL, '
            . 'expires_at DATETIME NOT NULL, '
            . 'consumed_at DATETIME NULL, '
            . 'attempts INT UNSIGNED NOT NULL DEFAULT 0, '
            . 'session_binding VARCHAR(64) NULL, '
            . 'source_network VARCHAR(64) NULL, '
            . 'PRIMARY KEY (id), '
            . 'KEY otp_requests_email (email, issued_at), '
            . 'KEY otp_requests_network (source_network, issued_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS otp_requests');
    }
};
