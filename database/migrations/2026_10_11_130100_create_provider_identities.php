<?php

declare(strict_types=1);

/**
 * Provider identities (data-model §5.2, C-32).
 *
 * A row is one way to reach one Customer: provider `email` today, `google`
 * when that adapter lands. Several rows per Customer are normal and expected —
 * that is the whole point of modelling the link as data (TR-111).
 *
 * **The foreign key cascades, explicitly (TR-167).** The row is a credential
 * belonging to the account: if the account is gone, it must not survive as a
 * way in. This is the one relationship in the identity schema where deleting
 * the parent does mean deleting the child.
 */
return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE provider_identities ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'customer_id INTEGER NOT NULL '
                . '  REFERENCES customers (id) ON DELETE CASCADE, '
                . 'provider TEXT NOT NULL, '
                . 'subject TEXT NOT NULL, '
                . 'email_at_provider TEXT NULL, '
                . 'linked_at TEXT NOT NULL, '
                . 'last_used_at TEXT NULL, '
                . 'UNIQUE (provider, subject)'
                . ')',
            );

            $pdo->exec('CREATE INDEX provider_identities_customer ON provider_identities (customer_id)');

            return;
        }

        $pdo->exec(
            'CREATE TABLE provider_identities ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'customer_id BIGINT UNSIGNED NOT NULL, '
            . 'provider VARCHAR(32) NOT NULL, '
            . 'subject VARCHAR(191) NOT NULL, '
            . 'email_at_provider VARCHAR(191) NULL, '
            . 'linked_at DATETIME NOT NULL, '
            . 'last_used_at DATETIME NULL, '
            . 'PRIMARY KEY (id), '
            . 'UNIQUE KEY provider_identities_subject (provider, subject), '
            . 'KEY provider_identities_customer (customer_id), '
            . 'CONSTRAINT provider_identities_customer_fk FOREIGN KEY (customer_id) '
            . '  REFERENCES customers (id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS provider_identities');
    }
};
