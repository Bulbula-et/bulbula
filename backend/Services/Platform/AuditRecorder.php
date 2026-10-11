<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Platform;

use Bulbula\Backend\Domain\Platform\ActorType;
use Bulbula\Backend\Domain\Platform\AuditEntry;
use Bulbula\Backend\Repositories\Platform\AuditEntryRepository;
use Bulbula\Backend\Support\Clock;
use InvalidArgumentException;

/**
 * Records a privileged action, or fails the action that needed it.
 *
 * **Nothing here swallows an error.** If the audit write fails, the exception
 * travels back to the caller, which by then has not yet committed its change —
 * that is what "unwritable audit means the action fails" (TRD §34, TR-08)
 * means in practice. Recording an action we could not audit would be the one
 * failure the audit trail exists to prevent.
 *
 * TR-08 asks for the entry to be written **in the same transaction as the
 * change**: this service therefore never opens a transaction of its own — it
 * participates in the caller's, and a caller with no transaction yet is a
 * caller that has not started changing anything.
 */
final class AuditRecorder
{
    public function __construct(
        private readonly AuditEntryRepository $entries,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws InvalidArgumentException when the entry could not describe who acted
     */
    public function record(
        ActorType $actorType,
        ?int $actorId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $reason = null,
    ): AuditEntry {
        $action = trim($action);

        if ($action === '') {
            throw new InvalidArgumentException('An audit entry needs an action.');
        }

        if ($actorType->isAuthenticated() && $actorId === null) {
            throw new InvalidArgumentException(
                'A ' . $actorType->value . ' audit entry needs the id of the actor who acted.',
            );
        }

        if (!$actorType->isAuthenticated() && $actorId !== null) {
            throw new InvalidArgumentException('A guest audit entry cannot name an actor id.');
        }

        if (($targetType === null) !== ($targetId === null) && $targetType === null) {
            throw new InvalidArgumentException('An audit target needs both a type and an id.');
        }

        $entry = new AuditEntry(
            actorType: $actorType,
            actorId: $actorId,
            action: $action,
            targetType: $targetType,
            targetId: $targetId,
            reason: $reason === null ? null : trim($reason),
            occurredAt: $this->clock->now(),
        );

        $this->entries->append($entry);

        return $entry;
    }
}
