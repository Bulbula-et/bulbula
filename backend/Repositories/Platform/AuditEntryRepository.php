<?php

declare(strict_types=1);

namespace Bulbula\Backend\Repositories\Platform;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Domain\Platform\AuditEntry;

/**
 * The audit trail's only door.
 *
 * **Append-only by construction:** this class has no update and no delete
 * method, so "no interface edits or deletes an audit entry" (OM-6.2) is a fact
 * about the code rather than a rule somebody has to remember. A test asserts
 * that no statement anywhere updates or deletes `audit_entries`.
 *
 * Reading the trail is an Administrator action (`audit.read`, OM §4) and is not
 * built yet; when it is, it belongs here as a query that takes the actor whose
 * permission was checked — not as a way round this class.
 */
final class AuditEntryRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return int the row id of the entry written */
    public function append(AuditEntry $entry): int
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO audit_entries '
            . '(occurred_at, actor_type, actor_id, action, target_type, target_id, reason) '
            . 'VALUES (:occurred_at, :actor_type, :actor_id, :action, :target_type, :target_id, :reason)',
        );

        $statement->execute([
            'occurred_at' => $entry->occurredAt->format('Y-m-d H:i:s'),
            'actor_type'  => $entry->actorType->value,
            'actor_id'    => $entry->actorId,
            'action'      => $entry->action,
            'target_type' => $entry->targetType,
            'target_id'   => $entry->targetId,
            'reason'      => $entry->reason,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }
}
