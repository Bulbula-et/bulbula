<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Services\Directory;

use Bulbula\Backend\Domain\Directory\BusinessState;
use Bulbula\Backend\Domain\Platform\ActorType;
use Bulbula\Backend\Exceptions\HttpNotFoundException;
use Bulbula\Backend\Exceptions\PublicationRefusedException;
use Bulbula\Backend\Repositories\Platform\AuditEntryRepository;
use Bulbula\Backend\Services\Directory\PublicationGate;
use Bulbula\Backend\Services\Directory\PublishListingService;
use Bulbula\Backend\Services\Platform\AuditRecorder;
use Bulbula\Tests\Support\DatabaseTestCase;
use Bulbula\Tests\Support\DirectoryScenario;
use Bulbula\Tests\Support\RecordingLogger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * The publication gate, from the outside (TR-49, C-19).
 *
 * The invariant this suite exists for is the one the spec states as behaviour:
 *
 * > Given a Listing with no linked Permission record
 * > When publication is attempted by any path, including import
 * > Then publication is refused.                                    (TR-49)
 *
 * "By any path" is why the gate is a single object every publisher goes
 * through: these tests call the one path that exists and assert the refusal
 * names *every* unmet requirement, so a caller can fix them all at once.
 */
final class PublishListingServiceTest extends DatabaseTestCase
{
    private DirectoryScenario $scenario;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scenario = new DirectoryScenario($this->database(), $this->clock);
        $this->logger = new RecordingLogger();
    }

    #[Test]
    public function publication_is_refused_without_a_permission_record(): void
    {
        $business = $this->scenario->business();
        $this->scenario->branch($business);
        $this->scenario->businesses->assignCategory($business->id, $this->scenario->category()->id, true, $this->clock->now());

        try {
            $this->service()->publish($business->id, ActorType::Operator, 7);
            self::fail('publication without a permission record must be refused (TR-49)');
        } catch (PublicationRefusedException $refusal) {
            self::assertSame(['permission_record'], $refusal->reasons);
        }

        self::assertSame(BusinessState::Draft->value, $this->scenario->storedStatus($business->id), 'nothing was published');
        self::assertFalse($this->scenario->auditHas('listing.published'), 'a refused publication writes no success entry');
    }

    #[Test]
    public function every_unmet_requirement_is_named_at_once(): void
    {
        $business = $this->scenario->business();

        try {
            $this->service()->publish($business->id, ActorType::Operator, 7);
            self::fail('an incomplete Listing cannot be published');
        } catch (PublicationRefusedException $refusal) {
            self::assertSame(
                ['exactly_one_primary_category', 'at_least_one_branch', 'permission_record'],
                $refusal->reasons,
            );
        }
    }

    #[Test]
    public function a_business_with_no_primary_branch_is_refused(): void
    {
        $business = $this->scenario->business();
        $this->scenario->branch($business, isPrimary: false);
        $this->scenario->businesses->assignCategory($business->id, $this->scenario->category()->id, true, $this->clock->now());
        $this->scenario->permission($business);

        try {
            $this->service()->publish($business->id, ActorType::Operator, 7);
            self::fail('exactly one Branch is primary (§3.2)');
        } catch (PublicationRefusedException $refusal) {
            self::assertSame(['exactly_one_primary_branch'], $refusal->reasons);
        }
    }

    #[Test]
    public function a_granted_permission_completes_the_requirements(): void
    {
        $business = $this->scenario->business();
        $this->scenario->branch($business);
        $this->scenario->businesses->assignCategory($business->id, $this->scenario->category()->id, true, $this->clock->now());
        $this->scenario->permission($business);

        $published = $this->service()->publish($business->id, ActorType::Operator, 7);

        self::assertSame(BusinessState::Published, $published->state);
        self::assertSame($this->scenario->clockNow(), $this->scenario->storedTimestamp($business->id, 'published_at'));
        self::assertTrue($this->scenario->auditHas('listing.published', $business->id));
    }

    #[Test]
    public function a_withdrawn_permission_stops_publication(): void
    {
        $business = $this->scenario->business();
        $this->scenario->branch($business);
        $this->scenario->businesses->assignCategory($business->id, $this->scenario->category()->id, true, $this->clock->now());
        $record = $this->scenario->permission($business);

        $this->clock->advance(60);
        $this->scenario->permissions->withdraw($record->id, 'Owner changed their mind', $this->clock->now());

        try {
            $this->service()->publish($business->id, ActorType::Operator, 7);
            self::fail('the most recent Permission record is a withdrawal (TR-49, PRD TS-5)');
        } catch (PublicationRefusedException $refusal) {
            self::assertSame(['permission_record'], $refusal->reasons);
        }
    }

    #[Test]
    public function a_renewed_grant_after_a_withdrawal_restores_the_requirement(): void
    {
        $business = $this->scenario->business();
        $this->scenario->branch($business);
        $this->scenario->businesses->assignCategory($business->id, $this->scenario->category()->id, true, $this->clock->now());
        $record = $this->scenario->permission($business);

        $this->clock->advance(60);
        $this->scenario->permissions->withdraw($record->id, 'Paused', $this->clock->now());

        $this->clock->advance(60);
        $this->scenario->permission($business, 'Tsegaye Bekele (again)');

        $published = $this->service()->publish($business->id, ActorType::Operator, 7);

        self::assertSame(BusinessState::Published, $published->state, 'the newest record is a grant again');
    }

    #[Test]
    public function a_closed_listing_cannot_be_published(): void
    {
        $business = $this->scenario->business();
        $this->scenario->branch($business);
        $this->scenario->businesses->assignCategory($business->id, $this->scenario->category()->id, true, $this->clock->now());
        $this->scenario->permission($business);
        $this->scenario->businesses->markClosed($business->id, 'Ceased trading', $this->clock->now(), null);

        try {
            $this->service()->publish($business->id, ActorType::Operator, 7);
            self::fail('closure is terminal for publication (TR-53)');
        } catch (PublicationRefusedException $refusal) {
            self::assertSame(['closed'], $refusal->reasons);
        }
    }

    #[Test]
    public function unpublishing_requires_a_reason_and_records_it(): void
    {
        $business = $this->scenario->business();
        $this->scenario->branch($business);
        $this->scenario->businesses->assignCategory($business->id, $this->scenario->category()->id, true, $this->clock->now());
        $this->scenario->permission($business);
        $this->service()->publish($business->id, ActorType::Operator, 7);

        try {
            $this->service()->unpublish($business->id, '   ', ActorType::Operator, 7);
            self::fail('unpublishing always carries a reason');
        } catch (InvalidArgumentException) {
            self::assertSame(BusinessState::Published->value, $this->scenario->storedStatus($business->id), 'a refused unpublish changes nothing');
        }

        $unpublished = $this->service()->unpublish($business->id, 'Permission withdrawn by telephone', ActorType::Operator, 7);

        self::assertSame(BusinessState::Unpublished, $unpublished->state);
        self::assertTrue($this->scenario->auditHas('listing.unpublished', $business->id, 'Permission withdrawn by telephone'));
    }

    #[Test]
    public function publishing_an_unknown_business_is_not_found(): void
    {
        $this->expectException(HttpNotFoundException::class);

        $this->service()->publish(999, ActorType::Operator, 7);
    }

    #[Test]
    public function a_refusal_is_logged_with_its_reasons(): void
    {
        $business = $this->scenario->business();

        try {
            $this->service()->publish($business->id, ActorType::Operator, 7);
        } catch (PublicationRefusedException) {
            // asserted below
        }

        $refusals = array_values(array_filter(
            $this->logger->records,
            static fn (array $record): bool => $record['message'] === 'listing.publication_refused',
        ));

        self::assertCount(1, $refusals);
        self::assertSame($business->id, $refusals[0]['context']['business']);
    }

    private function service(): PublishListingService
    {
        return new PublishListingService(
            $this->scenario->businesses,
            $this->scenario->permissions,
            new PublicationGate($this->scenario->businesses, $this->scenario->branches),
            new AuditRecorder(new AuditEntryRepository($this->database()), $this->clock),
            $this->database(),
            $this->logger,
            $this->clock,
        );
    }
}
