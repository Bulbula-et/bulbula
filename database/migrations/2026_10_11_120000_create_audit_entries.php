<?php

declare(strict_types=1);

/**
 * The audit trail (C-29, TR-08, TR-171, OM §6).
 *
 * Three decisions are visible in this schema, and each is a requirement:
 *
 * 1. **Append-only.** There is no `updated_at` and no soft-delete column,
 *    because no interface edits or deletes an audit entry (OM-6.2) — a mutable
 *    audit trail records what somebody wanted it to say.
 * 2. **No foreign key on `actor_id` or `target_id`.** Deleting a Customer must
 *    not silently delete audit history (TR-167, OM-6.3), and a cascading key
 *    is exactly how that happens. The reference is opaque on purpose: the entry
 *    survives the row it describes.
 * 3. **`reason` is nullable here but required by the operations that need it.**
 *    "Why" is mandatory for a published-data change and meaningless for a
 *    sign-in, so the rule lives with the action, not in the column definition.
 *
 * Field names follow the operations model: actor, action, target, timestamp,
 * reason (OM §6, C-29).
 */
return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            // The test engine. Types are affinities here; the shape is the same.
            $pdo->exec(
                'CREATE TABLE audit_entries ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'occurred_at TEXT NOT NULL, '
                . 'actor_type TEXT NOT NULL, '
                . 'actor_id INTEGER NULL, '
                . 'action TEXT NOT NULL, '
                . 'target_type TEXT NULL, '
                . 'target_id INTEGER NULL, '
                . 'reason TEXT NULL'
                . ')',
            );

            $pdo->exec('CREATE INDEX audit_entries_occurred_at ON audit_entries (occurred_at)');
            $pdo->exec('CREATE INDEX audit_entries_actor ON audit_entries (actor_type, actor_id)');
            $pdo->exec('CREATE INDEX audit_entries_action ON audit_entries (action, occurred_at)');
            $pdo->exec('CREATE INDEX audit_entries_target ON audit_entries (target_type, target_id)');

            return;
        }

        $pdo->exec(
            'CREATE TABLE audit_entries ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'occurred_at DATETIME NOT NULL, '
            . 'actor_type VARCHAR(16) NOT NULL, '
            . 'actor_id BIGINT UNSIGNED NULL, '
            . 'action VARCHAR(64) NOT NULL, '
            . 'target_type VARCHAR(32) NULL, '
            . 'target_id BIGINT UNSIGNED NULL, '
            . 'reason VARCHAR(255) NULL, '
            . 'PRIMARY KEY (id), '
            . 'KEY audit_entries_occurred_at (occurred_at), '
            . 'KEY audit_entries_actor (actor_type, actor_id), '
            . 'KEY audit_entries_action (action, occurred_at), '
            . 'KEY audit_entries_target (target_type, target_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS audit_entries');
    }
};
