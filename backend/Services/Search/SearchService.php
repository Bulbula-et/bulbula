<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Search;

use Bulbula\Backend\Domain\Directory\Area;
use Bulbula\Backend\Domain\Directory\Category;
use Bulbula\Backend\Domain\Directory\Slug;
use Bulbula\Backend\Repositories\Directory\AreaRepository;
use Bulbula\Backend\Repositories\Directory\CategoryRepository;
use Bulbula\Backend\Repositories\Directory\SubCityRepository;
use Bulbula\Backend\Services\Directory\PublicListingService;
use Psr\Log\LoggerInterface;

/**
 * Query interpretation, staged matching, filtering, ranking and the designed
 * failure paths (search-design §7–§11).
 *
 * The stages run in order and the first that produces results wins, with the
 * stage recorded so behaviour is observable (§7.1). Filters apply at every
 * stage (MT-1) and a filter-only request is a browse, not a search (MT-6).
 * Ordering is the configured lexicographic key chain over the inputs §9.1
 * admits — no numeric weights exist here on purpose (OR-1, D-09 open), and
 * no commercial input can reach this class at all (OR-3, SP-1, SP-8).
 */
final class SearchService
{
    /** The stages, in the order §7.1 runs them. */
    private const STAGE_EXACT = 'exact';
    private const STAGE_FULL = 'full';
    private const STAGE_PREFIX = 'prefix';
    private const STAGE_RELAXED = 'relaxed';
    private const STAGE_BROWSE = 'browse';
    private const STAGE_NONE = 'none';

    private readonly int $pageSize;
    private readonly int $maxQueryChars;
    private readonly int $maxTerms;

    /** @var list<string> */
    private readonly array $rankingKeys;

    /**
     * @param array<string, mixed> $settings from `config/search.php`
     */
    public function __construct(
        private readonly SearchPort $documents,
        private readonly CategoryRepository $categories,
        private readonly AreaRepository $areas,
        private readonly SubCityRepository $subCities,
        private readonly PublicListingService $listings,
        private readonly TextNormaliser $normaliser,
        private readonly LoggerInterface $logger,
        array $settings,
    ) {
        $pageSize = $settings['page_size'] ?? 20;
        $maxQueryChars = $settings['max_query_chars'] ?? 120;
        $maxTerms = $settings['max_terms'] ?? 8;

        $this->pageSize = is_int($pageSize) ? $pageSize : 20;
        $this->maxQueryChars = is_int($maxQueryChars) ? $maxQueryChars : 120;
        $this->maxTerms = is_int($maxTerms) ? $maxTerms : 8;

        $keys = [];

        foreach ((array) ($settings['ranking_keys'] ?? []) as $key) {
            if (is_string($key)) {
                $keys[] = $key;
            }
        }

        $this->rankingKeys = $keys !== [] ? $keys : ['id'];
    }

    /**
     * Answer a search or a filtered request: the staged match, or the
     * designed zero-result destination (§7, §11).
     */
    public function query(SearchRequest $request): SearchResults
    {
        $raw = trim($request->query);

        if (mb_strlen($raw, 'UTF-8') > $this->maxQueryChars) {
            // MT-4: rejected cleanly, never run.
            return new SearchResults([], 0, 1, $this->pageSize, self::STAGE_NONE, $raw, true);
        }

        $filters = $this->filtersFor($request);
        $terms = array_slice($this->normaliser->tokens($raw), 0, $this->maxTerms);
        $normalised = $this->normaliser->normalise($raw);

        if ($normalised === '') {
            if ($filters->isEmpty()) {
                return new SearchResults([], 0, 1, $this->pageSize, self::STAGE_NONE, $raw);
            }

            // MT-6: filter-only is a browse.
            return $this->pageOf($this->ranked($this->documents->browse($filters), []), [], self::STAGE_BROWSE, $raw, $request->page);
        }

        $found = $this->stages($normalised, $terms, $filters);

        if ($found !== null) {
            return $this->pageOf(
                $this->ranked($found['rows'], $found['nameMatches']),
                $found['nameMatches'],
                $found['stage'],
                $raw,
                $request->page,
            );
        }

        return new SearchResults(
            [],
            0,
            1,
            $this->pageSize,
            self::STAGE_RELAXED,
            $raw,
            false,
            $this->zeroPlan($request, $filters, $terms, $normalised),
        );
    }

    /**
     * A browse listing: Category, Subcategory, Category × Area or Area
     * (public-web-ux §4, §6, §7).
     */
    public function browse(?Category $category, ?Category $parent, ?Area $area, SearchRequest $request): BrowseResults
    {
        $filters = $this->filtersFor(new SearchRequest(
            '',
            $category,
            $area,
            false,
            null,
            false,
        ));

        $rows = $this->ranked($this->documents->browse($filters), []);
        $total = count($rows);
        $page = $this->pageSlice($rows, $request->page);

        return new BrowseResults(
            $this->items($page, []),
            $total,
            $this->pageNumber($request->page, $total),
            $this->pageSize,
            $this->subcategoryFacets($category, $parent),
            $category !== null ? $this->areaFacets($category) : [],
            $area !== null ? $this->categoryFacets($area) : [],
            $parent !== null ? $this->siblingFacets($parent, $category) : [],
        );
    }

    /**
     * The discovery indexes (public-web-ux §3, §5) and the homepage tiles:
     * the catalogue and the geography, each with its real count, and nothing
     * empty listed at all (C-04).
     */
    public function catalogue(): Catalogue
    {
        $all = $this->categories->all();
        $children = [];
        $topLevel = [];

        foreach ($all as $category) {
            if ($category->parentId === null) {
                $topLevel[] = $category;
            } else {
                $children[$category->parentId][] = $category;
            }
        }

        $categories = [];

        foreach ($topLevel as $category) {
            $set = [$category->id];
            $childTiles = [];

            foreach ($children[$category->id] ?? [] as $child) {
                $set[] = $child->id;
                $childCount = $this->documents->distinctBusinessesForCategories([$child->id]);

                if ($childCount > 0) {
                    $childTiles[] = ['category' => $child, 'count' => $childCount];
                }
            }

            $count = $this->documents->distinctBusinessesForCategories($set);

            if ($count > 0) {
                $categories[] = ['category' => $category, 'count' => $count, 'children' => $childTiles];
            }
        }

        $areaCounts = [];
        $areasBySubCity = [];

        foreach ($this->areas->all() as $area) {
            $count = $this->documents->distinctBusinessesForArea($area->id);

            if ($count > 0) {
                $areaCounts[$area->id] = $count;
                $areasBySubCity[$area->subCityId][] = ['area' => $area, 'count' => $count];
            }
        }

        $areas = [];

        foreach ($this->subCities->byIds(array_keys($areasBySubCity)) as $subCity) {
            $areas[] = ['subCity' => $subCity, 'areas' => $areasBySubCity[$subCity->id] ?? []];
        }

        return new Catalogue($categories, $areas);
    }

    /**
     * The stage chain (§7.1): exact, full-text, prefix, relaxed — first
     * non-empty wins. Returns null when every stage is empty.
     *
     * @param list<string> $terms
     *
     * @return array{rows: list<MatchRow>, nameMatches: array<int, bool>, stage: string}|null
     */
    private function stages(string $normalised, array $terms, SearchFilters $filters): ?array
    {
        // Stage 1 — exact: the query equals a Business name, or a Category or
        // Area label (the label set is resolved in PHP against the same
        // normaliser; Aliases deliberately do not promote a query into this
        // stage — AL-4: they change whether something matches, not its order).
        $labelCategoryIds = [];
        $labelAreaIds = [];

        foreach ($this->categories->all() as $category) {
            if ($this->normaliser->normalise($category->name) === $normalised
                || ($category->nameAm !== null && $this->normaliser->normalise($category->nameAm) === $normalised)) {
                $labelCategoryIds[] = $category->id;
            }
        }

        foreach ($this->areas->all() as $area) {
            if ($this->normaliser->normalise($area->name) === $normalised
                || ($area->nameAm !== null && $this->normaliser->normalise($area->nameAm) === $normalised)) {
                $labelAreaIds[] = $area->id;
            }
        }

        $exactTerms = $terms !== [] ? $terms : ($normalised !== '' ? [$normalised] : []);
        $rows = $this->documents->exactName($normalised, $labelCategoryIds, $labelAreaIds, $filters);

        if ($rows !== []) {
            return [
                'rows'        => $rows,
                'nameMatches' => $this->documents->nameCoverage($this->ids($rows), $exactTerms),
                'stage'       => self::STAGE_EXACT,
            ];
        }

        if ($terms === []) {
            // MT-5: terms the index ignores must not silently produce an
            // empty result — fall through to the designed destination.
            return null;
        }

        // Stage 2 — full-text: all significant terms present.
        $rows = $this->documents->allTerms($terms, $filters);

        if ($rows !== []) {
            return [
                'rows'        => $rows,
                'nameMatches' => $this->documents->nameCoverage($this->ids($rows), $terms),
                'stage'       => self::STAGE_FULL,
            ];
        }

        // Stage 3 — prefix: every term but the last exactly, the last as it
        // is being typed.
        $required = array_slice($terms, 0, -1);
        $prefix = (string) end($terms);
        $rows = $this->documents->prefix($required, $prefix, $filters);

        if ($rows !== []) {
            return [
                'rows'        => $rows,
                'nameMatches' => $this->documents->nameCoveragePrefix($this->ids($rows), $required, $prefix),
                'stage'       => self::STAGE_PREFIX,
            ];
        }

        // Stage 4 — relaxed: any significant term present, presented as
        // broader by the view (UFL-A7.2).
        $rows = $this->documents->anyTerm($terms, $filters);

        if ($rows !== []) {
            return [
                'rows'        => $rows,
                'nameMatches' => $this->documents->nameCoverage($this->ids($rows), $terms),
                'stage'       => self::STAGE_RELAXED,
            ];
        }

        return null;
    }

    /**
     * The zero-result destination (§11, user-flows §A7): drop the most
     * restrictive filter **and name it**, widen the area to its Sub-city
     * where that is the honest broader answer (§8.1), and otherwise point at
     * real destinations — never at unrelated businesses (ZR-3).
     *
     * @param list<string> $terms
     */
    private function zeroPlan(SearchRequest $request, SearchFilters $filters, array $terms, string $normalised): ZeroResultPlan
    {
        $labels = [
            'open_now'   => 'Open now',
            'min_rating' => 'Minimum rating',
            'verified'   => 'Verified only',
            'area'       => 'Area',
            'category'   => 'Category',
            'wider_area' => 'Area (searched wider)',
        ];

        foreach ($filters->activeKeys() as $key) {
            $probe = $this->withoutFilter($filters, $key, $request);
            $found = $this->stages($normalised, $terms, $probe);

            if ($found === null && $key === 'area' && $request->area !== null) {
                // The Sub-city is the broader fallback for an Area (§8.1).
                $wider = new SearchFilters(
                    $filters->categoryIds,
                    null,
                    $request->area->subCityId,
                    $filters->openNow,
                    $filters->minRating,
                    $filters->verifiedOnly,
                );
                $found = $this->stages($normalised, $terms, $wider);

                if ($found !== null) {
                    return $this->plan(
                        'wider_area',
                        'Area — widened to ' . $this->subCityName($request->area->subCityId),
                        $found,
                        $request,
                    );
                }
            }

            if ($found !== null) {
                return $this->plan($key, $labels[$key] ?? $key, $found, $request);
            }
        }

        // ZR-1: recorded for coverage and Alias work — with no Guest
        // identifier of any kind (SRCH-8, TR-202).
        $this->logger->info('search.zero_result', [
            'q'       => $request->query,
            'filters' => $filters->activeKeys(),
        ]);

        return new ZeroResultPlan(null, null, null, $this->alternatives($request));
    }

    /**
     * @param array{rows: list<MatchRow>, nameMatches: array<int, bool>, stage: string} $found
     */
    private function plan(string $key, string $label, array $found, SearchRequest $request): ZeroResultPlan
    {
        $this->logger->info('search.zero_result', [
            'q'            => $request->query,
            'filters'      => $key,
            'dropped'      => $key,
        ]);

        return new ZeroResultPlan(
            $key,
            $label,
            $this->pageOf($this->ranked($found['rows'], $found['nameMatches']), $found['nameMatches'], $found['stage'], $request->query, 1),
            $this->alternatives($request),
        );
    }

    /** @return list<array{label: string, url: string}> */
    private function alternatives(SearchRequest $request): array
    {
        $links = [];

        if ($request->category !== null) {
            $parent = $request->category->parentId !== null
                ? $this->categoryById($request->category->parentId)
                : null;
            $links[] = [
                'label' => $request->category->name . ' in all areas',
                'url'   => $this->categoryUrl($parent, $request->category),
            ];
        }

        if ($request->area !== null) {
            $links[] = [
                'label' => 'Everything in ' . $request->area->name,
                'url'   => '/a/' . $request->area->slug->value,
            ];
        }

        if ($request->category !== null && $request->area !== null) {
            $links[] = [
                'label' => 'Browse all Categories',
                'url'   => '/categories',
            ];
        }

        $links[] = ['label' => 'Browse all Areas', 'url' => '/areas'];
        $links[] = ['label' => 'Browse all Categories', 'url' => '/categories'];

        return $links;
    }

    private function withoutFilter(SearchFilters $filters, string $key, SearchRequest $request): SearchFilters
    {
        return match ($key) {
            'open_now'   => new SearchFilters($filters->categoryIds, $filters->areaId, $filters->subCityId, false, $filters->minRating, $filters->verifiedOnly),
            'min_rating' => new SearchFilters($filters->categoryIds, $filters->areaId, $filters->subCityId, $filters->openNow, null, $filters->verifiedOnly),
            'verified'   => new SearchFilters($filters->categoryIds, $filters->areaId, $filters->subCityId, $filters->openNow, $filters->minRating, false),
            'area'       => new SearchFilters($filters->categoryIds, null, null, $filters->openNow, $filters->minRating, $filters->verifiedOnly),
            'category'   => new SearchFilters([], $filters->areaId, $filters->subCityId, $filters->openNow, $filters->minRating, $filters->verifiedOnly),
            default      => $filters,
        };
    }

    private function filtersFor(SearchRequest $request): SearchFilters
    {
        $categoryIds = [];

        if ($request->category !== null) {
            $categoryIds[] = $request->category->id;

            foreach ($this->categories->all() as $candidate) {
                if ($candidate->parentId === $request->category->id) {
                    $categoryIds[] = $candidate->id;
                }
            }
        }

        return new SearchFilters(
            $categoryIds,
            $request->area?->id,
            null,
            $request->openNow,
            $request->minRating,
            $request->verifiedOnly,
        );
    }

    /**
     * Ordering within a match stage: the configured key chain over the
     * §9.1 inputs, lexicographic and deterministic (OR-2, OR-5). There is no
     * score, and nothing commercial can enter here (OR-3).
     *
     * @param list<MatchRow>      $rows
     * @param array<int, bool>    $nameMatches
     *
     * @return list<MatchRow>
     */
    private function ranked(array $rows, array $nameMatches): array
    {
        usort($rows, function (MatchRow $left, MatchRow $right) use ($nameMatches): int {
            foreach ($this->rankingKeys as $key) {
                $comparison = match ($key) {
                    'name_match'     => ((int) ($nameMatches[$left->businessId] ?? false)) <=> ((int) ($nameMatches[$right->businessId] ?? false)),
                    'verified'       => ((int) $left->verified) <=> ((int) $right->verified),
                    'verified_at'    => $this->compareNullable($left->verifiedAt?->getTimestamp(), $right->verifiedAt?->getTimestamp()),
                    'completeness'   => $this->compareNullable($left->completeness, $right->completeness),
                    'rating_count'   => $left->ratingCount <=> $right->ratingCount,
                    'rating_average' => $this->compareNullable($left->ratingAverage, $right->ratingAverage),
                    'id'             => $right->businessId <=> $left->businessId,
                    default          => 0,
                };

                if ($comparison !== 0) {
                    // Every key sorts high-first except the tiebreaker.
                    return $key === 'id' ? $comparison : -$comparison;
                }
            }

            return $right->businessId <=> $left->businessId;
        });

        return $rows;
    }

    /** NULL sorts last, whatever the direction — an unscored input is neutral. */
    private function compareNullable(int|float|null $left, int|float|null $right): int
    {
        if ($left === null) {
            return $right === null ? 0 : -1;
        }

        if ($right === null) {
            return 1;
        }

        return $left <=> $right;
    }

    /**
     * The Catalogue node at a slug, or null — the surface answers not-found
     * for a destination that does not exist (architecture.md §6).
     */
    public function categoryBySlug(string $slug): ?Category
    {
        $address = Slug::tryFromString($slug);

        return $address === null ? null : $this->categories->findBySlug($address);
    }

    public function areaBySlug(string $slug): ?Area
    {
        $address = Slug::tryFromString($slug);

        return $address === null ? null : $this->areas->findBySlug($address);
    }

    /**
     * @param list<MatchRow> $rows
     */
    private function pageOf(array $rows, array $nameMatches, string $stage, string $query, int $page): SearchResults
    {
        return new SearchResults(
            $this->items($this->pageSlice($rows, $page), $nameMatches),
            count($rows),
            $this->pageNumber($page, count($rows)),
            $this->pageSize,
            $stage,
            $query,
        );
    }

    /**
     * @param list<MatchRow> $rows
     *
     * @return list<MatchRow>
     */
    private function pageSlice(array $rows, int $page): array
    {
        return array_slice($rows, ($this->pageNumber($page, count($rows)) - 1) * $this->pageSize, $this->pageSize);
    }

    private function pageNumber(int $page, int $total): int
    {
        $last = max(1, (int) ceil($total / max(1, $this->pageSize)));

        return min(max(1, $page), $last);
    }

    /**
     * @param list<MatchRow>   $rows
     * @param array<int, bool> $nameMatches
     *
     * @return list<SearchResultItem>
     */
    private function items(array $rows, array $nameMatches): array
    {
        if ($rows === []) {
            return [];
        }

        $profiles = $this->listings->findMany(array_map(static fn (MatchRow $row): int => $row->businessId, $rows));
        $items = [];

        foreach ($rows as $row) {
            $profile = $profiles[$row->businessId] ?? null;

            if ($profile === null) {
                continue;
            }

            $items[] = new SearchResultItem($profile, $nameMatches[$row->businessId] ?? false);
        }

        return $items;
    }

    /**
     * @param list<MatchRow> $rows
     *
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (MatchRow $row): int => $row->businessId, $rows);
    }

    /**
     * Subcategory chips for a Category page — only those with published
     * Listings (IAR-14).
     *
     * @return list<array{category: Category, count: int}>
     */
    private function subcategoryFacets(?Category $category, ?Category $parent): array
    {
        $within = $category ?? $parent;

        if ($within === null || $within->parentId !== null) {
            return [];
        }

        $facets = [];

        foreach ($this->categories->all() as $candidate) {
            if ($candidate->parentId !== $within->id) {
                continue;
            }

            $count = $this->documents->distinctBusinessesForCategories([$candidate->id]);

            if ($count > 0) {
                $facets[] = ['category' => $candidate, 'count' => $count];
            }
        }

        return $facets;
    }

    /**
     * Areas where this Category has published Listings — the Category × Area
     * navigation (IA §3.2). Keys are Area ids.
     *
     * @return list<array{area: Area, count: int}>
     */
    private function areaFacets(Category $category): array
    {
        $set = [$category->id];

        foreach ($this->categories->all() as $candidate) {
            if ($candidate->parentId === $category->id) {
                $set[] = $candidate->id;
            }
        }

        $counts = $this->documents->areaFacetsForCategories($set);
        $facets = [];

        foreach ($this->areas->byIds(array_keys($counts)) as $area) {
            $facets[] = ['area' => $area, 'count' => $counts[$area->id]];
        }

        return $facets;
    }

    /**
     * Categories present in one Area (IA §3.2), rolled up to the top level —
     * a 24-hour pharmacy *is* a pharmacy.
     *
     * @return list<array{category: Category, count: int}>
     */
    private function categoryFacets(Area $area): array
    {
        $counts = $this->documents->categoryFacetsForArea($area->id);
        $facets = [];

        foreach ($this->categories->all() as $category) {
            if ($category->parentId === null && isset($counts[$category->id])) {
                $facets[] = ['category' => $category, 'count' => $counts[$category->id]];
            }
        }

        return $facets;
    }

    /**
     * Sibling Subcategories of the same parent (IA §3.2), each with its own
     * real count.
     *
     * @return list<array{category: Category, count: int}>
     */
    private function siblingFacets(Category $parent, ?Category $category): array
    {
        $facets = [];

        foreach ($this->categories->all() as $candidate) {
            if ($candidate->parentId !== $parent->id || $candidate->id === $category?->id) {
                continue;
            }

            $count = $this->documents->distinctBusinessesForCategories([$candidate->id]);

            if ($count > 0) {
                $facets[] = ['category' => $candidate, 'count' => $count];
            }
        }

        return $facets;
    }

    /** The Catalogue node with this id — for parent paths (IAR-21). */
    public function categoryById(int $id): ?Category
    {
        foreach ($this->categories->all() as $category) {
            if ($category->id === $id) {
                return $category;
            }
        }

        return null;
    }

    private function categoryUrl(?Category $parent, Category $category): string
    {
        return $parent === null
            ? '/c/' . $category->slug->value
            : '/c/' . $parent->slug->value . '/' . $category->slug->value;
    }

    private function subCityName(int $subCityId): string
    {
        foreach ($this->subCities->byIds([$subCityId]) as $subCity) {
            return $subCity->name;
        }

        return (string) $subCityId;
    }
}
