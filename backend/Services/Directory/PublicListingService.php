<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Directory;

use Bulbula\Backend\Domain\Directory\Branch;
use Bulbula\Backend\Domain\Directory\Business;
use Bulbula\Backend\Domain\Directory\Slug;
use Bulbula\Backend\Repositories\Directory\AreaRepository;
use Bulbula\Backend\Repositories\Directory\BranchRepository;
use Bulbula\Backend\Repositories\Directory\BusinessRepository;
use Bulbula\Backend\Repositories\Directory\CategoryRepository;
use Bulbula\Backend\Repositories\Directory\SubCityRepository;
use Bulbula\Backend\Repositories\Platform\RedirectRepository;

/**
 * The public read path: what a stranger may see, and what a moved address
 * answers (PR-1…PR-6, IAR-3).
 *
 * Publication is the only visibility switch there is. A published Business is
 * public; a permanently closed one stays public, because closure is a state
 * and the URL outlives it (PR-2, TR-53, UXP-7.8). Everything else — draft, in
 * review, unpublished — is indistinguishable from "no such Business" to the
 * outside, which is deliberate: a page that says "this exists but you may not
 * see it" tells a stranger something it should not (C-08, TR-35).
 */
final class PublicListingService
{
    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly CategoryRepository $categories,
        private readonly BranchRepository $branches,
        private readonly AreaRepository $areas,
        private readonly SubCityRepository $subCities,
        private readonly RedirectRepository $redirects,
    ) {
    }

    /** The profile at a slug, or null when nothing is publicly visible there. */
    public function find(Slug $slug): ?ListingProfile
    {
        $business = $this->businesses->findBySlug($slug);

        if ($business === null || (!$business->isPublished() && !$business->isClosed())) {
            return null;
        }

        return $this->assemble($business);
    }

    /**
     * The profiles for a page of results, keyed by Business id — the same
     * assembly as `find`, so a card shows exactly what the profile would
     * (TD-05).
     *
     * @param list<int> $ids
     *
     * @return array<int, ListingProfile>
     */
    public function findMany(array $ids): array
    {
        $profiles = [];

        foreach (array_unique($ids) as $id) {
            $business = $this->businesses->findById($id);

            if ($business === null || (!$business->isPublished() && !$business->isClosed())) {
                continue;
            }

            $profiles[$id] = $this->assemble($business);
        }

        return $profiles;
    }

    private function assemble(Business $business): ListingProfile
    {
        $categories = [];
        $primary    = null;

        foreach ($this->categories->classificationsFor($business->id) as $classification) {
            $categories[] = $classification['category'];

            if ($classification['isPrimary'] && $primary === null) {
                $primary = $classification['category'];
            }
        }

        $parents = [];

        foreach ($categories as $category) {
            if ($category->parentId === null || isset($parents[$category->parentId])) {
                continue;
            }

            $parent = $this->categories->findById($category->parentId);

            if ($parent !== null) {
                $parents[$parent->id] = $parent;
            }
        }

        $branches = $this->branches->listForBusiness($business->id);

        return new ListingProfile(
            $business,
            $primary,
            $categories,
            $parents,
            $branches,
            $this->areas->byIds(array_map(static fn (Branch $branch): int => $branch->areaId, $branches)),
            $this->subCities->byIds(array_map(static fn (Branch $branch): int => $branch->subCityId, $branches)),
        );
    }

    /**
     * The address a moved path answers now, when one was recorded (IAR-3).
     *
     * Only an internal destination is ever returned: the recorded target is a
     * path Bulbula generated, and handing back an absolute URL from a row
     * would turn this into an open redirect (APP-2.13).
     */
    public function permanentRedirectFor(string $path): ?string
    {
        $target = $this->redirects->find($path);

        return $target !== null && str_starts_with($target, '/') ? $target : null;
    }
}
