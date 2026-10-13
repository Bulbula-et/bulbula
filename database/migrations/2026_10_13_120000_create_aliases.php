<?php

declare(strict_types=1);

return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE aliases ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'target_type TEXT NOT NULL, '
                . 'target_id INTEGER NOT NULL, '
                . 'alias_text TEXT NOT NULL, '
                . 'alias_normalised TEXT NOT NULL, '
                . 'language TEXT NULL, '
                . 'created_at TEXT NOT NULL'
                . ')',
            );
            $pdo->exec(
                'CREATE UNIQUE INDEX aliases_target_alias ON aliases (target_type, target_id, alias_normalised)',
            );

            return;
        }

        $pdo->exec(
            'CREATE TABLE aliases ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'target_type VARCHAR(16) NOT NULL, '
            . 'target_id BIGINT UNSIGNED NOT NULL, '
            . 'alias_text VARCHAR(120) NOT NULL, '
            . 'alias_normalised VARCHAR(191) NOT NULL, '
            . 'language VARCHAR(8) NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'PRIMARY KEY (id), '
            . 'UNIQUE KEY aliases_target_alias (target_type, target_id, alias_normalised), '
            . 'KEY aliases_target (target_type, target_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS aliases');
    }
};
