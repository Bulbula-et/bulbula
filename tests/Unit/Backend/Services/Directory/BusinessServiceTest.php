<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Services\Directory;

use Bulbula\Backend\Domain\Directory\BusinessState;
use Bulbula\Backend\Domain\Directory\Slug;
use Bulbula\Backend\Domain\Platform\ActorType;
use Bulbula\Backend\Exceptions\ConcurrencyConflictException;
use Bulbula\Backend\Exceptions\HttpNotFoundException;
use Bulbula\Backend\Exceptions\ValidationException;
use Bulbula\Backend\Repositories\Platform\AuditEntryRepository;
use Bulbula\Backend\Services\Directory\BusinessService;
use Bulbula\Backend\Services\Platform\AuditRecorder;
use Bulbula\Tests\Support\DatabaseTestCase;
use Bulbula\Tests\Support\DirectoryScenario;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Directory's write side (build order §9 step 3): creation with duplicate
 * candidates surfaced (TR-55), versioned edits (TR-57), slug changes that
 * leave a redirect (IAR-3), exactly one primary Branch (D-03), exactly one
 * primary Category (D-57), and closure as a state (TR-53).
 */
final class BusinessServiceTest extends DatabaseTestCase
{
    private DirectoryScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scenario = new DirectoryScenario($this->database(), $this->clock);
    }

    #[Test]
    public function creating_a_business_records_it_as_draft_and_audits_it(): void
    {
        $creation = $this->service()->create(
            Slug::fromString('tsegaye-pharmacy'),
            'Tsegaye Pharmacy',
            ActorType::Operator,
            7,
        );

        self::assertSame(BusinessState::Draft, $creation->business->state);
        self::assertFalse($creation->hasDuplicateCandidates());
        self::assertTrue($this->scenario->auditHas('listing.created', $creation->business->id));
    }

    #[Test]
    public function a_taken_slug_is_refused(): void
    {
        $this->scenario->business('Tsegaye Pharmacy', Slug::fromString('tsegaye-pharmacy'));

        $this->expectException(ValidationException::class);

        $this->service()->create(Slug::fromString('tsegaye-pharmacy'), 'Another Pharmacy', ActorType::Operator, 7);
    }

    #[Test]
    public function an_empty_name_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->create(Slug::fromString('nameless'), '   ', ActorType::Operator, 7);
    }

    #[Test]
    public function duplicate_candidates_are_surfaced_not_refused(): void
    {
        $this->scenario->business('Tsegaye Pharmacy', Slug::fromString('tsegaye-pharmacy'));

        $creation = $this->service()->create(
            Slug::fromString('tsegaye-pharmacy-bole'),
            'Tsegaye Pharmacy',
            ActorType::Operator,
            7,
        );

        self::assertTrue($creation->hasDuplicateCandidates(), 'a genuinely similar business exists (TR-55)');
        self::assertCount(1, $creation->duplicateCandidates);
        self::assertSame('Tsegaye Pharmacy', $creation->duplicateCandidates[0]->name);
        self::assertNotSame($creation->business->id, $creation->duplicateCandidates[0]->id, 'the new record is not its own candidate');
    }

    #[Test]
    public function a_duplicate_phone_number_is_a_candidate_too(): void
    {
        $existing = $this->scenario->business('Tsegaye Pharmacy', Slug::fromString('tsegaye-pharmacy'));
        $this->scenario->branch($existing);

        $creation = $this->service()->create(
            Slug::fromString('mekdes-pharmacy'),
            'Mekdes Pharmacy',
            ActorType::Operator,
            7,
            null,
            null,
            null,
            '+251911000000',
        );

        self::assertTrue($creation->hasDuplicateCandidates(), 'the branch phone is already on file (TR-55)');
    }

    #[Test]
    public function a_stale_version_is_a_conflict_not_an_overwrite(): void
    {
        $business = $this->scenario->business();

        // Someone else edits first, moving the version from 1 to 2.
        $this->service()->updateProfile($business->id, ['short_description' => 'First edit'], 1, ActorType::Operator, 7);

        try {
            $this->service()->updateProfile($business->id, ['short_description' => 'Second edit'], 1, ActorType::Operator, 7);
            self::fail('the stale version should have been refused (TR-57)');
        } catch (ConcurrencyConflictException $conflict) {
            self::assertSame(1, $conflict->expectedVersion);
        }

        self::assertSame('First edit', $this->scenario->storedColumn($business->id, 'short_description'), 'the first edit survives');
        self::assertSame(2, $this->scenario->storedVersion($business->id), 'a refused edit changes nothing');
    }

    #[Test]
    public function editing_under_the_current_version_applies_and_bumps_it(): void
    {
        $business = $this->scenario->business();

        $updated = $this->service()->updateProfile(
            $business->id,
            ['name' => 'Tsegaye Pharmacy and Store', 'website' => 'https://example.test'],
            1,
            ActorType::Operator,
            7,
        );

        self::assertSame('Tsegaye Pharmacy and Store', $updated->name);
        self::assertSame('https://example.test', $updated->website);
        self::assertSame(2, $updated->version);
        self::assertTrue($this->scenario->auditHas('listing.updated', $business->id));
    }

    #[Test]
    public function changing_a_slug_leaves_a_redirect(): void
    {
        $business = $this->scenario->business('Tsegaye Pharmacy', Slug::fromString('tsegaye-pharmacy'));

        $renamed = $this->service()->changeSlug(
            $business->id,
            Slug::fromString('tsegaye-pharmacy-bole'),
            1,
            ActorType::Operator,
            7,
        );

        self::assertSame('tsegaye-pharmacy-bole', $renamed->slug->value);
        self::assertSame('/b/tsegaye-pharmacy-bole', $this->scenario->redirects->find('/b/tsegaye-pharmacy'), 'the old address resolves (IAR-3)');
        self::assertTrue($this->scenario->auditHas('listing.slug_changed', $business->id));
    }

    #[Test]
    public function changing_a_slug_to_a_taken_one_is_refused_and_leaves_nothing_behind(): void
    {
        $first = $this->scenario->business('Tsegaye Pharmacy', Slug::fromString('tsegaye-pharmacy'));
        $second = $this->scenario->business('Mekdes Pharmacy', Slug::fromString('mekdes-pharmacy'));

        try {
            $this->service()->changeSlug($second->id, Slug::fromString('tsegaye-pharmacy'), 1, ActorType::Operator, 7);
            self::fail('a taken slug must be refused');
        } catch (ValidationException) {
            self::assertSame('mekdes-pharmacy', $this->scenario->storedColumn($second->id, 'slug'));
            self::assertNull($this->scenario->redirects->find('/b/mekdes-pharmacy'));
            self::assertSame(1, $this->scenario->storedVersion($second->id), 'a refused rename changes nothing');
            self::assertSame('tsegaye-pharmacy', $this->scenario->storedColumn($first->id, 'slug'));
        }
    }

    #[Test]
    public function the_first_branch_is_the_primary_one_and_only_one(): void
    {
        $business = $this->scenario->business();

        $first = $this->service()->addBranch($business->id, $this->scenario->area()->id, $this->scenario->subCity()->id, ActorType::Operator, 7);
        $second = $this->service()->addBranch($business->id, $this->scenario->area()->id, $this->scenario->subCity()->id, ActorType::Operator, 7);

        self::assertTrue($first->isPrimary, 'the first Branch is primary (D-03)');
        self::assertFalse($second->isPrimary);
        self::assertSame(1, $this->scenario->branches->primaryCount($business->id));
        self::assertSame(2, $this->scenario->branches->countForBusiness($business->id));
        self::assertSame(
            $business->version + 2,
            $this->scenario->storedVersion($business->id),
            'each additive change moves the version, so a concurrent profile edit fails loudly (TR-57)',
        );
        self::assertTrue($this->scenario->auditHas('listing.branch_added', $business->id));
    }

    #[Test]
    public function a_branch_whose_area_is_not_in_the_sub_city_named_is_refused(): void
    {
        $business = $this->scenario->business();
        $other = $this->scenario->subCities->create(Slug::fromString('yeka'), 'Yeka', null, 'Addis Ababa', $this->clock->now());

        $this->expectException(ValidationException::class);

        $this->service()->addBranch($business->id, $this->scenario->area()->id, $other->id, ActorType::Operator, 7);
    }

    #[Test]
    public function a_branch_for_an_unknown_area_is_not_found(): void
    {
        $business = $this->scenario->business();

        $this->expectException(HttpNotFoundException::class);

        $this->service()->addBranch($business->id, 999, $this->scenario->subCity()->id, ActorType::Operator, 7);
    }

    #[Test]
    public function classifying_sets_exactly_one_primary_category(): void
    {
        $business = $this->scenario->business();
        $other = $this->scenario->categories->create(null, Slug::fromString('restaurants'), 'Restaurants', null, $this->clock->now());

        $this->service()->classify($business->id, $this->scenario->category()->id, true, ActorType::Operator, 7);
        $this->service()->classify($business->id, $other->id, true, ActorType::Operator, 7);

        self::assertSame(1, $this->scenario->businesses->primaryCategoryCount($business->id), 'a new primary demotes the old one (D-57)');
        self::assertSame(2, $this->scenario->businesses->categoryCount($business->id));
        self::assertTrue($this->scenario->businesses->hasCategory($business->id, $other->id));
    }

    #[Test]
    public function classifying_the_same_category_twice_is_refused(): void
    {
        $business = $this->scenario->business();
        $this->service()->classify($business->id, $this->scenario->category()->id, true, ActorType::Operator, 7);

        $this->expectException(ValidationException::class);

        $this->service()->classify($business->id, $this->scenario->category()->id, false, ActorType::Operator, 7);
    }

    #[Test]
    public function closing_is_a_state_with_a_reason_and_the_record_survives(): void
    {
        $business = $this->scenario->business();

        $closed = $this->service()->close($business->id, 'Business ceased trading', ActorType::Operator, 7);

        self::assertTrue($closed->isClosed());
        self::assertSame('Business ceased trading', $this->scenario->storedColumn($business->id, 'closure_reason'));
        self::assertNotNull($closed->closedAt);
        self::assertSame($business->slug->value, $this->scenario->storedColumn($business->id, 'slug'), 'the URL survives closure (TR-53)');
        self::assertNotNull($this->scenario->businesses->findById($business->id), 'the record is not deleted (TR-53)');
        self::assertTrue($this->scenario->auditHas('listing.closed', $business->id, 'Business ceased trading'));
    }

    #[Test]
    public function closing_an_already_closed_listing_is_refused(): void
    {
        $business = $this->scenario->business();
        $this->service()->close($business->id, 'First reason', ActorType::Operator, 7);

        $this->expectException(ValidationException::class);

        $this->service()->close($business->id, 'Second reason', ActorType::Operator, 7);
    }

    #[Test]
    public function a_website_that_is_not_http_or_https_is_refused_and_not_stored(): void
    {
        $business = $this->scenario->business();

        try {
            $this->service()->updateProfile(
                $business->id,
                ['website' => 'javascript:alert(1)'],
                $business->version,
                ActorType::Operator,
                7,
            );
            self::fail('a stored address may not carry a scheme a page would execute (APP-2.12)');
        } catch (ValidationException $refusal) {
            self::assertSame(['website'], array_keys($refusal->errors));
        }

        self::assertNull($this->scenario->storedColumn($business->id, 'website'), 'the refused value never reached the column');
    }

    #[Test]
    public function a_stored_website_is_canonicalised_before_it_is_checked(): void
    {
        $business = $this->scenario->business();

        $this->service()->updateProfile(
            $business->id,
            ['website' => '  HTTPS://Example.com/branches  '],
            $business->version,
            ActorType::Operator,
            7,
        );

        self::assertSame('https://Example.com/branches', $this->scenario->storedColumn($business->id, 'website'));
    }

    #[Test]
    public function a_social_link_that_is_not_http_or_https_is_refused(): void
    {
        $business = $this->scenario->business();

        try {
            $this->service()->updateProfile(
                $business->id,
                ['social_links' => ['facebook' => 'data:text/html;base64,PHNjcmlwdD4=']],
                $business->version,
                ActorType::Operator,
                7,
            );
            self::fail('a stored social link may not carry a scheme a page would execute (APP-2.12)');
        } catch (ValidationException $refusal) {
            self::assertSame(['social_links'], array_keys($refusal->errors));
        }

        self::assertNull($this->scenario->storedColumn($business->id, 'social_links'));
    }

    #[Test]
    public function valid_social_links_are_stored_as_a_map_and_read_back(): void
    {
        $business = $this->scenario->business();

        $updated = $this->service()->updateProfile(
            $business->id,
            ['social_links' => ['facebook' => 'https://facebook.com/tsegaye']],
            $business->version,
            ActorType::Operator,
            7,
        );

        self::assertSame(['facebook' => 'https://facebook.com/tsegaye'], $updated->socialLinks);
    }

    #[Test]
    public function a_social_link_map_beyond_its_bound_is_refused(): void
    {
        $business = $this->scenario->business();
        $links    = [];

        foreach (range(1, 13) as $number) {
            $links['platform-' . $number] = 'https://example.com/' . $number;
        }

        $this->expectException(ValidationException::class);

        $this->service()->updateProfile($business->id, ['social_links' => $links], $business->version, ActorType::Operator, 7);
    }

    private function service(): BusinessService
    {
        return new BusinessService(
            $this->scenario->businesses,
            $this->scenario->branches,
            $this->scenario->categories,
            $this->scenario->areas,
            $this->scenario->subCities,
            $this->scenario->redirects,
            new AuditRecorder(new AuditEntryRepository($this->database()), $this->clock),
            $this->scenario->indexer(),
            $this->database(),
            $this->clock,
        );
    }
}
