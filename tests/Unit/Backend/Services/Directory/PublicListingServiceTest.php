<?php

declare(strict_types=1);

namespace Bulbula\Tests\Unit\Backend\Services\Directory;

use Bulbula\Backend\Domain\Directory\Category;
use Bulbula\Backend\Domain\Directory\Slug;
use Bulbula\Backend\Services\Directory\PublicListingService;
use Bulbula\Tests\Support\DatabaseTestCase;
use Bulbula\Tests\Support\DirectoryScenario;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a stranger may see (PR-1…PR-6, IAR-3).
 *
 * The rule that shapes every assertion here: visibility has exactly one switch
 * — publication — and a closed Business keeps its address (PR-2, UXP-7.8).
 * Everything else is indistinguishable from "no such Business" from outside.
 */
final class PublicListingServiceTest extends DatabaseTestCase
{
    private DirectoryScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scenario = new DirectoryScenario($this->database(), $this->clock);
    }

    #[Test]
    public function a_published_business_is_read_with_its_primary_category_first(): void
    {
        $business = $this->scenario->publishedBusiness();

        $subcategory = $this->scenario->categories->create(
            $this->scenario->category()->id,
            Slug::fromString('24-hour'),
            '24-hour',
            null,
            $this->clock->now(),
        );

        $this->scenario->businesses->assignCategory($business->id, $subcategory->id, false, $this->clock->now());

        $profile = $this->service()->find($business->slug);

        self::assertNotNull($profile);
        self::assertSame($business->slug->value, $profile->business->slug->value);
        self::assertSame($this->scenario->category()->id, $profile->primaryCategory?->id);
        self::assertSame(
            [$this->scenario->category()->id, $subcategory->id],
            array_map(static fn (Category $category): int => $category->id, $profile->categories),
            'the primary Category leads, the secondary follows (D-57)',
        );
        self::assertSame($this->scenario->category()->id, $profile->parentOf($subcategory)?->id);
    }

    #[Test]
    public function the_primary_branch_leads_and_carries_its_area_and_sub_city(): void
    {
        $business = $this->scenario->publishedBusiness();
        $this->scenario->branch($business, isPrimary: false);

        $profile = $this->service()->find($business->slug);

        self::assertNotNull($profile);
        self::assertCount(2, $profile->branches);
        self::assertTrue($profile->branches[0]->isPrimary, 'the primary Branch is first (D-03)');
        self::assertSame('Bole Bulbula', $profile->areaOf($profile->branches[0])?->name);
        self::assertSame('Bole', $profile->subCityOf($profile->branches[0])?->name);
    }

    #[Test]
    public function a_closed_business_stays_public(): void
    {
        $business = $this->scenario->publishedBusiness();

        $this->scenario->businesses->markClosed($business->id, 'Business ceased trading', $this->clock->now(), 7);

        $profile = $this->service()->find($business->slug);

        self::assertNotNull($profile, 'closure keeps the URL and the history (TR-53, PR-2)');
        self::assertTrue($profile->business->isClosed());
    }

    #[Test]
    public function a_draft_is_not_public(): void
    {
        $business = $this->scenario->business();
        $this->scenario->branch($business);
        $this->scenario->businesses->assignCategory($business->id, $this->scenario->category()->id, true, $this->clock->now());

        self::assertNull($this->service()->find($business->slug));
    }

    #[Test]
    public function an_unpublished_listing_is_not_public(): void
    {
        $business = $this->scenario->publishedBusiness();

        $this->scenario->businesses->markUnpublished($business->id, $this->clock->now(), 7);

        self::assertNull($this->service()->find($business->slug));
    }

    #[Test]
    public function an_unknown_slug_is_not_found(): void
    {
        self::assertNull($this->service()->find(Slug::fromString('nobody-here')));
    }

    #[Test]
    public function a_moved_address_resolves_to_an_internal_target_only(): void
    {
        $this->scenario->redirects->record('/b/old-name', '/b/new-name', 'slug_change', $this->clock->now());
        $this->scenario->redirects->record('/b/somewhere-else', 'https://elsewhere.example.com/', 'slug_change', $this->clock->now());

        self::assertSame('/b/new-name', $this->service()->permanentRedirectFor('/b/old-name'));
        self::assertNull(
            $this->service()->permanentRedirectFor('/b/somewhere-else'),
            'an absolute target would make the address an open redirect (APP-2.13)',
        );
        self::assertNull($this->service()->permanentRedirectFor('/b/never-recorded'));
    }

    private function service(): PublicListingService
    {
        return new PublicListingService(
            $this->scenario->businesses,
            $this->scenario->categories,
            $this->scenario->branches,
            $this->scenario->areas,
            $this->scenario->subCities,
            $this->scenario->redirects,
        );
    }
}
