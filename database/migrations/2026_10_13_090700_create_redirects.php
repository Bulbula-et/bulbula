<?php

declare(strict_types=1);

/**
 * Redirects — the record that keeps a URL alive after it changes (data-model §8.7).
 *
 * Required by the URL grammar: a Business profile URL survives a name change,
 * and a changed slug redirects permanently (IAR-3, SEO-4). The same table
 * serves a retired Category slug after a merge (C-23).
 *
 * `old_path` is unique: two live claims on one address would make the
 * redirect ambiguous, and the second writer is refused rather than silently
 * overwriting the first.
 */
return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE redirects ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'old_path TEXT NOT NULL UNIQUE, '
                . 'new_path TEXT NOT NULL, '
                . 'reason TEXT NOT NULL, '
                . 'created_at TEXT NOT NULL'
                . ')',
            );

            return;
        }

        $pdo->exec(
            'CREATE TABLE redirects ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'old_path VARCHAR(255) NOT NULL, '
            . 'new_path VARCHAR(255) NOT NULL, '
            . 'reason VARCHAR(120) NOT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (id), '
            . 'UNIQUE KEY redirects_old_path (old_path)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS redirects');
    }
};
