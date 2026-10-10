<?php

declare(strict_types=1);

namespace Bulbula\Backend\Database\Migrations;

use Bulbula\Backend\Database\Connection;

/**
 * A schema change with a way back.
 *
 * `down()` exists because M1 item 12 requires reversibility (TR-173) and
 * because a migration that cannot be undone is a migration nobody dares run
 * on a host they cannot reset. It does **not** make a shipped migration
 * editable: corrections are new migrations, never edits to an applied one
 * (architecture §10).
 *
 * A migration receives the connection, not a query builder, and never imports
 * application code — a migration that depends on a service stops being
 * replayable the moment that service changes.
 */
interface Migration
{
    /** Apply the change. */
    public function up(Connection $connection): void;

    /** Undo it, exactly: what `up()` created, `down()` removes. */
    public function down(Connection $connection): void;
}
