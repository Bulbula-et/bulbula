<?php

declare(strict_types=1);

namespace Bulbula\Tests\Feature\Search;

use Bulbula\Backend\Domain\Directory\Area;
use Bulbula\Backend\Domain\Directory\Business;
use Bulbula\Backend\Domain\Directory\Category;
use Bulbula\Backend\Domain\Directory\Slug;
use Bulbula\Backend\Domain\Platform\ActorType;
use Bulbula\Backend\Repositories\Platform\AuditEntryRepository;
use Bulbula\Backend\Repositories\Search\SearchDocumentRepository;
use Bulbula\Backend\Services\Directory\BusinessService;
use Bulbula\Backend\Services\Directory\PublicListingService;
use Bulbula\Backend\Services\Platform\AuditRecorder;
use Bulbula\Backend\Services\Search\SearchRequest;
use Bulbula\Backend\Services\Search\SearchService;
use Bulbula\Backend\Services\Search\TextNormaliser;
use Bulbula\Tests\Support\DatabaseTestCase;
use Bulbula\Tests\Support\DirectoryScenario;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/**
 * The matching contract (search-design §7–§11): stages in order, filters at
 * every stage (MT-1), browse as its own mode (MT-6), Aliases as matching
 * inputs (AL-3), query bounds (MT-4), and the designed zero-result
 * destination (§11, user-flows §A7).
 */
final class SearchMatchingTest extends DatabaseTestCase
{
    private DirectoryScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scenario = new DirectoryScenario($this->database(), $this->clock);
    }

    #[Test]
    public function an_exact_name_match_wins_the_exact_stage_and_is_a_name_match(): void
    {
        $this->publishIn('Alpha Pharmacy', $this->category('pharmacies', 'Pharmacies'), $this->area());

        $results = $this->search()->query(new SearchRequest(query: 'alpha pharmacy'));

        self::assertSame('exact', $results->stage);
        self::assertSame(1, $results->total);
        self::assertTrue($results->items[0]->nameMatch);
    }

    #[Test]
    public function an_exact_category_label_is_an_exact_match_too(): void
    {
        $this->publishIn('Alpha Pharmacy', $this->category('pharmacies', 'Pharmacies'), $this->area());

        $results = $this->search()->query(new SearchRequest(query: 'pharmacies'));

        self::assertSame('exact', $results->stage);
        self::assertSame(1, $results->total);
        // §9.1: the label is not the Business's name — the match location says so.
        self::assertFalse($results->items[0]->nameMatch);
    }

    #[Test]
    public function every_significant_term_present_answers_at_the_full_text_stage(): void
    {
        $this->publishIn(
            'Alpha Pharmacy',
            $this->category('pharmacies', 'Pharmacies'),
            $this->area(),
            'A pharmacy with a medicine counter and a consultation room.',
        );

        $results = $this->search()->query(new SearchRequest(query: 'medicine counter'));

        self::assertSame('full', $results->stage);
        self::assertSame(1, $results->total);
    }

    #[Test]
    public function the_last_token_being_typed_answers_at_the_prefix_stage(): void
    {
        $this->publishIn('Alpha Pharmacy', $this->category('pharmacies', 'Pharmacies'), $this->area());

        $results = $this->search()->query(new SearchRequest(query: 'alpha phar'));

        self::assertSame('prefix', $results->stage);
        self::assertSame(1, $results->total);
        self::assertTrue($results->items[0]->nameMatch);
    }

    #[Test]
    public function a_single_term_answers_relaxed_and_is_labelled_broader(): void
    {
        $this->publishIn('Alpha Pharmacy', $this->category('pharmacies', 'Pharmacies'), $this->area());
        $this->publishIn('Beta Traders', $this->category('shops', 'Shops'), $this->area());

        // "gamma" matches nothing strictly; "pharmacy" alone is present in
        // Alpha's text only as a term — the relaxed stage picks it up.
        $results = $this->search()->query(new SearchRequest(query: 'pharmacy gamma'));

        self::assertSame('relaxed', $results->stage);
        self::assertTrue($results->isBroader());
        self::assertSame(1, $results->total);
    }

    #[Test]
    public function filters_apply_at_every_stage_and_a_relaxed_match_cannot_escape_them(): void
    {
        $categoryA = $this->category('pharmacies', 'Pharmacies');
        $categoryB = $this->category('shops', 'Shops');
        $this->publishIn('Alpha Pharmacy', $categoryA, $this->area());
        $this->publishIn('Beta Pharmacy', $categoryB, $this->area());

        $unfiltered = $this->search()->query(new SearchRequest(query: 'pharmacy'));
        self::assertSame(2, $unfiltered->total);

        $filtered = $this->search()->query(new SearchRequest(query: 'pharmacy', category: $categoryA));
        self::assertSame(1, $filtered->total);
        self::assertSame('Alpha Pharmacy', $filtered->items[0]->profile->business->name);

        // …including at the relaxed stage (MT-1).
        $relaxed = $this->search()->query(new SearchRequest(query: 'pharmacy zebra', category: $categoryA));
        self::assertSame('relaxed', $relaxed->stage);
        self::assertSame(1, $relaxed->total);
        self::assertSame('Alpha Pharmacy', $relaxed->items[0]->profile->business->name);
    }

    #[Test]
    public function a_filter_only_request_is_a_browse_and_skips_the_stages(): void
    {
        $category = $this->category('pharmacies', 'Pharmacies');
        $this->publishIn('Alpha Pharmacy', $category, $this->area());

        $results = $this->search()->query(new SearchRequest(query: '', category: $category));

        self::assertSame('browse', $results->stage);
        self::assertSame(1, $results->total);
    }

    #[Test]
    public function an_alias_is_a_matching_input(): void
    {
        $category = $this->category('pharmacies', 'Pharmacies');
        $this->publishIn('Alpha Pharmacy', $category, $this->area());
        $this->scenario->aliases->add(
            'category',
            $category->id,
            'ፋርማሲዎች',
            'ፋርማሲዎች',
            'am',
            '2026-10-13 09:00:00',
        );
        $this->scenario->indexer()->syncFor($this->scenario->businesses->findBySlug(
            Slug::fromString('alpha-pharmacy'),
        )->id);

        $results = $this->search()->query(new SearchRequest(query: 'ፋርማሲዎች'));

        self::assertSame(1, $results->total);
        self::assertSame('Alpha Pharmacy', $results->items[0]->profile->business->name);
    }

    #[Test]
    public function a_name_match_sorts_above_a_description_match(): void
    {
        $category = $this->category('pharmacies', 'Pharmacies');
        $this->publishIn('Gamma Pharmacy', $category, $this->area());
        $this->publishIn('Delta Shop', $category, $this->area(), 'Everything for the pharmacy counter.');

        $results = $this->search()->query(new SearchRequest(query: 'pharmacy'));

        self::assertSame(2, $results->total);
        self::assertSame('Gamma Pharmacy', $results->items[0]->profile->business->name);
        self::assertTrue($results->items[0]->nameMatch);
        self::assertFalse($results->items[1]->nameMatch);
    }

    #[Test]
    public function an_excessive_query_is_rejected_cleanly_and_never_run(): void
    {
        $results = $this->search()->query(new SearchRequest(query: str_repeat('a very long query ', 20)));

        self::assertTrue($results->rejected);
        self::assertSame(0, $results->total);
    }

    #[Test]
    public function a_closed_listing_stays_findable_and_shows_as_closed(): void
    {
        $business = $this->publishIn('Alpha Pharmacy', $this->category('pharmacies', 'Pharmacies'), $this->area());

        $this->businessService()->close($business->id, 'owner retired', ActorType::Operator, 7);

        $results = $this->search()->query(new SearchRequest(query: 'alpha pharmacy'));

        self::assertSame(1, $results->total);
        self::assertSame('closed', $results->items[0]->profile->business->state->value);
    }

    #[Test]
    public function a_dropped_filter_is_named_and_its_results_are_the_labeled_set(): void
    {
        $this->publishIn('Alpha Pharmacy', $this->category('pharmacies', 'Pharmacies'), $this->area());

        // Nothing is verified yet (the verification module has not landed),
        // so the verified filter zeroes the set on its own.
        $results = $this->search()->query(new SearchRequest(query: 'alpha pharmacy', verifiedOnly: true));

        self::assertSame(0, $results->total);
        self::assertNotNull($results->zero);
        self::assertSame('verified', $results->zero->droppedFilter);
        self::assertSame('Verified only', $results->zero->droppedLabel);
        self::assertNotNull($results->zero->droppedResults);
        self::assertSame(1, $results->zero->droppedResults->total);
    }

    #[Test]
    public function a_true_zero_result_offers_real_destinations_and_never_unrelated_businesses(): void
    {
        $this->publishIn('Alpha Pharmacy', $this->category('pharmacies', 'Pharmacies'), $this->area());
        $category = $this->scenario->categories->findBySlug(Slug::fromString('pharmacies'));

        $results = $this->search()->query(new SearchRequest(query: 'zzz qqq', category: $category));

        self::assertSame(0, $results->total);
        self::assertNotNull($results->zero);
        self::assertNull($results->zero->droppedFilter);
        self::assertNull($results->zero->droppedResults);

        $labels = array_column($results->zero->alternatives, 'label');
        self::assertContains('Pharmacies in all areas', $labels);
        self::assertContains('Browse all Categories', $labels);
        self::assertContains('Browse all Areas', $labels);

        // ZR-3: nothing relevant means nothing relevant.
        self::assertSame([], $results->items);
    }

    #[Test]
    public function ranking_is_deterministic_across_repeated_queries(): void
    {
        $category = $this->category('pharmacies', 'Pharmacies');
        $this->publishIn('Alpha Pharmacy', $category, $this->area());
        $this->publishIn('Beta Pharmacy', $category, $this->area());
        $this->publishIn('Gamma Pharmacy', $category, $this->area());

        $first = $this->search()->query(new SearchRequest(query: 'pharmacy'));
        $second = $this->search()->query(new SearchRequest(query: 'pharmacy'));

        self::assertSame(
            array_map(static fn ($item): string => $item->profile->business->name, $first->items),
            array_map(static fn ($item): string => $item->profile->business->name, $second->items),
        );
    }

    private function category(string $slug, string $name): Category
    {
        return $this->scenario->categories->create(null, Slug::fromString($slug), $name, null, $this->clock->now());
    }

    private function area(): Area
    {
        return $this->scenario->area();
    }

    private function publishIn(string $name, Category $category, Area $area, ?string $description = null): Business
    {
        $business = $this->scenario->business($name);
        $this->scenario->branch($business, true, $area);
        $this->scenario->businesses->assignCategory($business->id, $category->id, true, $this->clock->now());
        $this->scenario->permission($business);

        if ($description !== null) {
            $this->businessService()->updateProfile(
                $business->id,
                ['short_description' => $description],
                1,
                ActorType::Operator,
                7,
            );
        }

        $this->scenario->businesses->markPublished($business->id, $this->clock->now(), 7);
        $this->scenario->indexer()->syncFor($business->id);

        return $this->scenario->businesses->findById($business->id) ?? $business;
    }

    private function businessService(): BusinessService
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

    private function search(): SearchService
    {
        $connection = $this->database();

        return new SearchService(
            new SearchDocumentRepository($connection),
            $this->scenario->categories,
            $this->scenario->areas,
            $this->scenario->subCities,
            new PublicListingService(
                $this->scenario->businesses,
                $this->scenario->categories,
                $this->scenario->branches,
                $this->scenario->areas,
                $this->scenario->subCities,
                $this->scenario->redirects,
            ),
            new TextNormaliser(2, []),
            new NullLogger(),
            [
                'page_size'        => 20,
                'max_query_chars'  => 120,
                'max_terms'        => 8,
                'ranking_keys'     => ['name_match', 'verified', 'verified_at', 'completeness', 'rating_count', 'rating_average', 'id'],
            ],
        );
    }
}
