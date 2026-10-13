<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Search;

/**
 * The search interface (implementation-plan §5.5, TR-48): one port, one
 * MariaDB-family adapter behind it (`Repositories\Search\SearchDocumentRepository`).
 *
 * The V1 adapter talks to the derived search-document tables with ordinary
 * indexed equality and prefix-range access — never a leading wildcard (MT-3)
 * and never a server-side full-text engine whose minimum token length and
 * stopword list cannot be configured on shared hosting (TR-196, IMR-12). A
 * future engine (search-design §12) implements this interface and nothing in
 * the application services changes.
 */
interface SearchPort
{
    /**
     * Stage 1 — the normalised query equals a Business name (name_norm), or a
     * Category/Area label the caller resolved (§7.1).
     *
     * @param list<int> $categoryIds
     * @param list<int> $areaIds
     *
     * @return list<MatchRow>
     */
    public function exactName(string $normalisedQuery, array $categoryIds, array $areaIds, SearchFilters $filters): array;

    /**
     * Stage 2 — every significant term is present (§7.1).
     *
     * @param list<string> $terms
     *
     * @return list<MatchRow>
     */
    public function allTerms(array $terms, SearchFilters $filters): array;

    /**
     * Stage 3 — all terms but the last present exactly, the last by prefix
     * (§7.1; the last token being typed).
     *
     * @param list<string> $requiredTerms
     *
     * @return list<MatchRow>
     */
    public function prefix(array $requiredTerms, string $prefixTerm, SearchFilters $filters): array;

    /**
     * Stage 4 — any significant term present; only when stricter stages
     * returned nothing, and presented as broader (§7.1).
     *
     * @param list<string> $terms
     *
     * @return list<MatchRow>
     */
    public function anyTerm(array $terms, SearchFilters $filters): array;

    /**
     * A filter-only request is a browse, not a search: no matching stages at
     * all (MT-6).
     *
     * @return list<MatchRow>
     */
    public function browse(SearchFilters $filters): array;

    /**
     * Whether every term of the query appears among each Business's *name*
     * tokens — the match-location input of §9.1 ("a name match is a stronger
     * signal"). Keys are business ids.
     *
     * @param list<int> $businessIds
     * @param list<string> $terms
     *
     * @return array<int, bool>
     */
    public function nameCoverage(array $businessIds, array $terms): array;

    /**
     * The same, for the prefix stage: the required terms covered by name
     * tokens and a name token starting with the prefix.
     *
     * @param list<int> $businessIds
     * @param list<string> $requiredTerms
     *
     * @return array<int, bool>
     */
    public function nameCoveragePrefix(array $businessIds, array $requiredTerms, string $prefixTerm): array;

    /**
     * Distinct Businesses matched by any of these Categories (a roll-up set:
     * a Category and its Subcategories), for the counts the browse tiles show.
     *
     * @param list<int> $categoryIds
     */
    public function distinctBusinessesForCategories(array $categoryIds): int;

    /** Distinct Businesses trading in this Area. */
    public function distinctBusinessesForArea(int $areaId): int;

    /**
     * Area facets of one Category set — Area id to distinct Business count
     * (the "Areas where this Category has published Listings" navigation).
     *
     * @param list<int> $categoryIds
     *
     * @return array<int, int>
     */
    public function areaFacetsForCategories(array $categoryIds): array;

    /**
     * Category facets of one Area — Category id to distinct Business count.
     *
     * @return array<int, int>
     */
    public function categoryFacetsForArea(int $areaId): array;

    /**
     * Replace one Business's document wholesale — the write path is one
     * transaction per Business so the three tables can never disagree (SD-2).
     */
    public function replaceDocument(int $businessId, SearchDocumentData $document): void;

    /** Remove one Business's document (SD-7: unpublishing deletes the row). */
    public function deleteDocument(int $businessId): void;

    /**
     * What the index currently holds, for the drift check (SD-5).
     *
     * @return list<array{businessId: int, status: string, updatedAt: string}>
     */
    public function documentSummaries(): array;
}
