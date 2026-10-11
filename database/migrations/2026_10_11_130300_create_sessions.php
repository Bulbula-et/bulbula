<?php

declare(strict_types=1);

/**
 * Sessions — one record, two transports (data-model §5.4, TD-02).
 *
 * - `token_hash` is the only form of the credential that exists server-side
 *   (S-3); it is unique because a token addresses exactly one session.
 * - `principal_type` + `principal_id` carry **no foreign key, deliberately**
 *   (TR-167): the row can belong to a Customer or to a staff user, and no
 *   single key can express that. Deleting a principal therefore does not
 *   delete sessions implicitly — revocation is an explicit act (S-7).
 * - `expires_at` is the absolute cap; the inactivity window is applied on
 *   every resolution against `last_active_at`, so both live in the same row
 *   without a second write path (S-6).
 */
return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE sessions ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'principal_type TEXT NOT NULL, '
                . 'principal_id INTEGER NOT NULL, '
                . 'token_hash TEXT NOT NULL UNIQUE, '
                . 'surface TEXT NOT NULL, '
                . 'created_at TEXT NOT NULL, '
                . 'last_active_at TEXT NOT NULL, '
                . 'expires_at TEXT NOT NULL, '
                . 'revoked_at TEXT NULL, '
                . 'user_agent TEXT NULL, '
                . 'network_marker TEXT NULL'
                . ')',
            );

            $pdo->exec('CREATE INDEX sessions_principal ON sessions (principal_type, principal_id)');

            return;
        }

        $pdo->exec(
            'CREATE TABLE sessions ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'principal_type VARCHAR(16) NOT NULL, '
            . 'principal_id BIGINT UNSIGNED NOT NULL, '
            . 'token_hash CHAR(64) NOT NULL, '
            . 'surface VARCHAR(16) NOT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'last_active_at DATETIME NOT NULL, '
            . 'expires_at DATETIME NOT NULL, '
            . 'revoked_at DATETIME NULL, '
            . 'user_agent VARCHAR(255) NULL, '
            . 'network_marker VARCHAR(64) NULL, '
            . 'PRIMARY KEY (id), '
            . 'UNIQUE KEY sessions_token (token_hash), '
            . 'KEY sessions_principal (principal_type, principal_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS sessions');
    }
};
