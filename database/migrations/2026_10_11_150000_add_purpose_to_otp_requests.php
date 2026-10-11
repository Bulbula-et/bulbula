<?php

declare(strict_types=1);

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\Migrations\Migration;

/**
 * A code is no longer only a sign-in code.
 *
 * With D-13 closed (owner, 2026-10-11), a provider identity may join an
 * existing Customer only after the person proves the mailbox with a code we
 * sent. That code is the same code, from the same table, subject to the same
 * limits — so the link intent is recorded **on the code** rather than in a
 * second table with a second set of limits to keep in step.
 *
 * - `purpose`     — `sign_in` (the default, and what every existing row is) or `link`
 * - `pending_provider` / `pending_subject` — the identity to attach when a
 *   `link` code is accepted. Both are null for a sign-in code.
 */
return new class () implements Migration {
    public function up(Connection $connection): void
    {
        $pdo = $connection->pdo();

        if ($connection->driver() === 'sqlite') {
            $pdo->exec("ALTER TABLE otp_requests ADD COLUMN purpose TEXT NOT NULL DEFAULT 'sign_in'");
            $pdo->exec('ALTER TABLE otp_requests ADD COLUMN pending_provider TEXT NULL');
            $pdo->exec('ALTER TABLE otp_requests ADD COLUMN pending_subject TEXT NULL');

            return;
        }

        $pdo->exec("ALTER TABLE otp_requests ADD COLUMN purpose VARCHAR(16) NOT NULL DEFAULT 'sign_in'");
        $pdo->exec('ALTER TABLE otp_requests ADD COLUMN pending_provider VARCHAR(32) NULL');
        $pdo->exec('ALTER TABLE otp_requests ADD COLUMN pending_subject VARCHAR(191) NULL');
    }

    public function down(Connection $connection): void
    {
        $pdo = $connection->pdo();

        $pdo->exec('ALTER TABLE otp_requests DROP COLUMN pending_subject');
        $pdo->exec('ALTER TABLE otp_requests DROP COLUMN pending_provider');
        $pdo->exec('ALTER TABLE otp_requests DROP COLUMN purpose');
    }
};
