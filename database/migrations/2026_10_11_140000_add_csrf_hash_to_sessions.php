<?php

declare(strict_types=1);

/**
 * A CSRF token bound to the session (CS-1, CS-4).
 *
 * Separate from the sessions migration rather than added to it, because that
 * one has already been applied on a host and migrations are forward-only once
 * shipped — editing it would leave two different schemas both claiming the
 * same file name (architecture §10).
 *
 * The column holds a **hash** of the token, for the same reason `token_hash`
 * does: a copy of this table must not hand over anything usable (S-3).
 * Nullable because sessions issued before this migration exist and are not
 * rewritten; a session without a CSRF token simply cannot authorise a
 * state-changing cookie request, which is the safe direction to fail.
 */
return new class () implements Bulbula\Backend\Database\Migrations\Migration {
    public function up(Bulbula\Backend\Database\Connection $connection): void
    {
        $connection->pdo()->exec('ALTER TABLE sessions ADD COLUMN csrf_hash VARCHAR(64) NULL');
    }

    public function down(Bulbula\Backend\Database\Connection $connection): void
    {
        // SQLite did not support DROP COLUMN before 3.35; both engines the
        // project runs on do, so the revert is a real revert rather than a
        // table rebuild.
        $connection->pdo()->exec('ALTER TABLE sessions DROP COLUMN csrf_hash');
    }
};
