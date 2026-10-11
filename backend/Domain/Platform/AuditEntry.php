<?php

declare(strict_types=1);

namespace Bulbula\Backend\Domain\Platform;

use DateTimeImmutable;

/**
 * One privileged action, recorded (C-29, OM §6).
 *
 * The five fields the operations model names are all here — actor, action,
 * target, time and reason — and nothing else. A value object rather than an
 * array, so a missing reason is a compile-time-shaped question rather than a
 * key somebody forgot.
 *
 * `actorId` is `null` for a Guest (there is no account to name) and never null
 * otherwise; `AuditRecorder` enforces that, because an entry that cannot say
 * who acted is not an audit trail.
 */
final readonly class AuditEntry
{
    public function __construct(
        public ActorType $actorType,
        public ?int $actorId,
        public string $action,
        public ?string $targetType,
        public ?int $targetId,
        public ?string $reason,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
