<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Search;

use Bulbula\Backend\Database\DatabaseTime;
use Bulbula\Backend\Domain\Directory\Branch;
use Bulbula\Backend\Domain\Directory\Business;
use Bulbula\Backend\Repositories\Directory\AliasRepository;
use Bulbula\Backend\Repositories\Directory\AreaRepository;
use Bulbula\Backend\Repositories\Directory\BranchRepository;
use Bulbula\Backend\Repositories\Directory\BusinessRepository;
use Bulbula\Backend\Repositories\Directory\CategoryRepository;
use Bulbula\Backend\Repositories\Directory\SubCityRepository;

/**
 * Search-document maintenance (search-design §3.3, SD-1…SD-7).
 *
 * The document is derived; the entity tables are the source of truth (SD-1).
 * A staff edit is visible immediately because `syncFor` runs inside the same
 * transaction as the write that changed the listing (SD-2). Unpublishing
 * deletes the row (SD-7); closure keeps it — a closed Business stays findable
 * and answers its URL with `status: closed` (PR-1, PR-2, TR-53), and the
 * matcher's exclusion list is draft, unpublished and removed, never closed
 * (MT-2).
 *
 * `rebuildAll` is safe to re-run (SD-4) and `driftReport` compares the index
 * against its source without writing (SD-5).
 */
final class SearchIndexer
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly CategoryRepository $categories,
        private readonly BranchRepository $branches,
        private readonly AreaRepository $areas,
        private readonly SubCityRepository $subCities,
        private readonly AliasRepository $aliases,
        private readonly SearchDocumentBuilder $builder,
        private readonly SearchPort $documents,
    ) {
    }

    /**
     * Bring one Business's document in step with its source rows — or remove
     * it, when nothing publicly visible remains (SD-2, SD-7).
     */
    public function syncFor(int $businessId): void
    {
        $business = $this->businesses->findById($businessId);
        $aliases = $this->groupedAliases();

        if ($business === null || (!$business->isPublished() && !$business->isClosed())) {
            $this->documents->deleteDocument($businessId);

            return;
        }

        $this->documents->replaceDocument(
            $businessId,
            $this->builder->build($this->sourcesFor($business, $aliases)),
        );
    }

    /**
     * Rebuild every document from source (SD-4). Safe to re-run: the result
     * is a function of the entity tables alone.
     *
     * @return array{rebuilt: int, removed: int}
     */
    public function rebuildAll(): array
    {
        $aliases = $this->groupedAliases();
        $rebuilt = 0;
        $removed = 0;
        $live = [];

        foreach ($this->businesses->statusSummaries() as $summary) {
            $live[$summary['id']] = true;
            $business = $this->businesses->findById($summary['id']);

            if ($business === null || (!$business->isPublished() && !$business->isClosed())) {
                $this->documents->deleteDocument($summary['id']);
                $removed++;

                continue;
            }

            $this->documents->replaceDocument(
                $business->id,
                $this->builder->build($this->sourcesFor($business, $aliases)),
            );
            $rebuilt++;
        }

        // SD-7 in reverse: documents for Businesses that no longer exist at
        // all are orphans, and an orphan is a lie about what is published.
        foreach ($this->documents->documentSummaries() as $document) {
            if (!isset($live[$document['businessId']])) {
                $this->documents->deleteDocument($document['businessId']);
                $removed++;
            }
        }

        return ['rebuilt' => $rebuilt, 'removed' => $removed];
    }

    /**
     * Compare document count and freshness against source (SD-5). Reports;
     * never writes.
     *
     * @return array{expected: int, indexed: int, missing: list<int>, stale: list<int>, orphaned: list<int>}
     */
    public function driftReport(): array
    {
        $expected = [];
        $freshness = [];

        foreach ($this->businesses->statusSummaries() as $summary) {
            $expected[$summary['id']] = true;
            $freshness[$summary['id']] = DatabaseTime::format($summary['updatedAt']);
        }

        $indexed = [];
        $stale = [];
        $orphaned = [];

        foreach ($this->documents->documentSummaries() as $document) {
            $id = $document['businessId'];
            $indexed[$id] = true;

            if (!isset($expected[$id])) {
                $orphaned[] = $id;

                continue;
            }

            if ($document['updatedAt'] < $freshness[$id]) {
                $stale[] = $id;
            }
        }

        $missing = [];

        foreach ($expected as $id => $present) {
            if (!isset($indexed[$id])) {
                $missing[] = $id;
            }
        }

        return [
            'expected' => count($expected),
            'indexed'  => count($indexed),
            'missing'  => $missing,
            'stale'    => $stale,
            'orphaned' => $orphaned,
        ];
    }

    /**
     * @param array<string, list<array{text: string, language: ?string}>> $aliases
     */
    private function sourcesFor(Business $business, array $aliases): DocumentSources
    {
        $categories = [];
        $parentIds = [];

        foreach ($this->categories->classificationsFor($business->id) as $classification) {
            $category = $classification['category'];
            $categories[] = [
                'categoryId' => $category->id,
                'isPrimary'  => $classification['isPrimary'],
                'name'       => $category->name,
                'nameAm'     => $category->nameAm,
                'aliases'    => $aliases['category:' . $category->id] ?? [],
            ];

            if ($category->parentId !== null) {
                $parentIds[$category->parentId] = true;
            }
        }

        // A Subcategory listing is also a listing of its parent: the path's
        // names and Aliases join the matching text (D-06, §3.2).
        $parentCategories = [];

        foreach (array_keys($parentIds) as $parentId) {
            $parent = $this->categories->findById($parentId);

            if ($parent === null) {
                continue;
            }

            $parentCategories[] = [
                'name'    => $parent->name,
                'nameAm'  => $parent->nameAm,
                'aliases' => $aliases['category:' . $parent->id] ?? [],
            ];
        }

        $branches = array_values(array_filter(
            $this->branches->listForBusiness($business->id),
            static fn (Branch $branch): bool => !$branch->isPermanentlyClosed(),
        ));

        $areaRows = $this->areas->byIds(array_map(static fn (Branch $branch): int => $branch->areaId, $branches));
        $subCityRows = $this->subCities->byIds(array_map(static fn (Branch $branch): int => $branch->subCityId, $branches));

        $areas = [];

        foreach ($areaRows as $area) {
            $areas[] = [
                'id'       => $area->id,
                'name'     => $area->name,
                'nameAm'   => $area->nameAm,
                'subCityId' => $area->subCityId,
                'aliases'  => $aliases['area:' . $area->id] ?? [],
            ];
        }

        $subCities = [];

        foreach ($subCityRows as $subCity) {
            $subCities[] = ['name' => $subCity->name, 'nameAm' => $subCity->nameAm];
        }

        $primary = null;

        foreach ($branches as $branch) {
            if ($branch->isPrimary) {
                $primary = $branch;

                break;
            }
        }

        $primary ??= $branches[0] ?? null;

        $labels = [];

        foreach ($branches as $branch) {
            if ($branch->label !== null && trim($branch->label) !== '') {
                $labels[] = $branch->label;
            }
        }

        $primaryCategory = null;

        foreach ($categories as $category) {
            if ($category['isPrimary']) {
                $primaryCategory = $category['categoryId'];

                break;
            }
        }

        $primaryCategory ??= $categories[0]['categoryId'] ?? null;

        return new DocumentSources(
            $business->name,
            $business->nameAm,
            $business->shortDescription,
            $business->longDescription,
            $business->isClosed() ? 'closed' : 'published',
            $primaryCategory,
            $primary?->areaId,
            $primary?->subCityId,
            $primary?->latitude,
            $primary?->longitude,
            DatabaseTime::format($business->createdAt),
            DatabaseTime::format($business->updatedAt),
            $categories,
            $parentCategories,
            $areas,
            $subCities,
            $labels,
        );
    }

    /**
     * @return array<string, list<array{text: string, language: ?string}>>
     */
    private function groupedAliases(): array
    {
        $grouped = [];

        foreach ($this->aliases->all() as $alias) {
            $grouped[$alias->targetType . ':' . $alias->targetId][] = [
                'text'     => $alias->aliasText,
                'language' => $alias->language,
            ];
        }

        return $grouped;
    }
}
