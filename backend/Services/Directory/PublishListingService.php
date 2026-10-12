<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Directory;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Database\DatabaseTime;
use Bulbula\Backend\Domain\Directory\Business;
use Bulbula\Backend\Domain\Platform\ActorType;
use Bulbula\Backend\Exceptions\HttpNotFoundException;
use Bulbula\Backend\Exceptions\PublicationRefusedException;
use Bulbula\Backend\Repositories\Directory\BusinessRepository;
use Bulbula\Backend\Repositories\Operations\PermissionRecordRepository;
use Bulbula\Backend\Services\Platform\AuditRecorder;
use Bulbula\Backend\Support\Clock;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishing and unpublishing a Listing (TR-49, TR-53, C-19).
 *
 * The order of operations is the rule:
 *
 * 1. the record is read;
 * 2. the **gate** is asked what is missing — a closed Business is refused
 *    outright, because closure is a terminal state and not a field to fill in
 *    (TR-53);
 * 3. only then is the state changed, **and the audit entry is written in the
 *    same transaction** — a publication that could not be recorded does not
 *    happen (TR-08).
 *
 * Unpublishing always carries a reason: it is a decision about someone's
 * listing, and "why" is the first question anyone will ask later. A withdrawal
 * of permission unpublishes through this same method (PRD TS-5), so the
 * requirement and the action cannot drift apart.
 */
final class PublishListingService
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly PermissionRecordRepository $permissions,
        private readonly PublicationGate $gate,
        private readonly AuditRecorder $audit,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws PublicationRefusedException when a requirement is unmet or the Business is closed
     * @throws HttpNotFoundException       when there is no such Business
     */
    public function publish(int $businessId, ActorType $actorType, ?int $actorId): Business
    {
        $business = $this->require($businessId);

        if ($business->isClosed()) {
            $this->logger->warning('listing.publication_refused', [
                'business' => $businessId,
                'reasons' => ['closed'],
            ]);

            throw new PublicationRefusedException(['closed']);
        }

        $permission = $this->permissions->findLatestForBusiness($businessId);
        $unmet = $this->gate->unmetRequirements($business, $permission);

        if ($unmet !== []) {
            $this->logger->warning('listing.publication_refused', [
                'business' => $businessId,
                'reasons' => $unmet,
            ]);

            throw new PublicationRefusedException($unmet);
        }

        $now = $this->clock->now();

        $this->transact(function () use ($businessId, $actorType, $actorId, $now): void {
            $this->businesses->markPublished($businessId, $now, $actorId);
            $this->audit->record($actorType, $actorId, 'listing.published', 'listing', $businessId);
        });

        $this->logger->info('listing.published', [
            'business' => $businessId,
            'published_at' => DatabaseTime::format($now),
        ]);

        return $this->require($businessId);
    }

    /**
     * @throws HttpNotFoundException when there is no such Business
     */
    public function unpublish(int $businessId, string $reason, ActorType $actorType, ?int $actorId): Business
    {
        $this->require($businessId);

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Unpublishing always carries a reason.');
        }

        $now = $this->clock->now();

        $this->transact(function () use ($businessId, $reason, $actorType, $actorId, $now): void {
            $this->businesses->markUnpublished($businessId, $now, $actorId);
            $this->audit->record($actorType, $actorId, 'listing.unpublished', 'listing', $businessId, $reason);
        });

        $this->logger->info('listing.unpublished', ['business' => $businessId, 'reason' => $reason]);

        return $this->require($businessId);
    }

    private function require(int $businessId): Business
    {
        return $this->businesses->findById($businessId)
            ?? throw new HttpNotFoundException('There is no Listing with that id.');
    }

    /**
     * The change and its audit entry commit together, or neither does (TR-08).
     *
     * @param callable(): void $work
     */
    private function transact(callable $work): void
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $work();
            $pdo->commit();
        } catch (Throwable $failure) {
            $pdo->rollBack();

            throw $failure;
        }
    }
}
