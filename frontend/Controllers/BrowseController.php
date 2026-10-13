<?php

declare(strict_types=1);

namespace Bulbula\Frontend\Controllers;

use Bulbula\Backend\Core\Request;
use Bulbula\Backend\Core\Response;
use Bulbula\Backend\Core\View;
use Bulbula\Backend\Domain\Directory\Area;
use Bulbula\Backend\Domain\Directory\Category;
use Bulbula\Backend\Exceptions\HttpNotFoundException;
use Bulbula\Backend\Services\Search\SearchRequest;
use Bulbula\Backend\Services\Search\SearchService;

/**
 * The browse destinations (IA §2.1, public-web-ux §3–§7): `/categories`,
 * `/c/{category}`, `/c/{category}/{subcategory}`, `/c/{category}/in/{area}`,
 * `/areas`, `/a/{area}`.
 *
 * These are the indexable discovery surfaces — search is not (SEO-8). Every
 * one of them answers not-found for a slug that does not exist, exactly like
 * the business profile: a registered path must keep its promise
 * (architecture.md §6), and a destination that does not exist is absent, not
 * broken.
 */
final class BrowseController
{
    private const MISS = 'No published listing answers that address.';

    public function __construct(
        private readonly View $view,
        private readonly SearchService $search,
    ) {
    }

    /** /categories — the taxonomy, with real counts (§3). */
    public function categories(Request $request): Response
    {
        return $this->view->render('layouts/app', [
            'title'       => 'Categories — Bulbula',
            'description' => 'Every kind of business Bulbula lists in Addis Ababa.',
            'content'     => $this->view->capture('pages/categories', [
                'catalogue' => $this->search->catalogue(),
            ]),
        ]);
    }

    /** /c/{category} and /c/{category}/{subcategory} (§4). */
    public function category(Request $request, array $vars): Response
    {
        $category = $this->requireCategory($vars['category'] ?? '');
        $parent = null;

        if (($vars['subcategory'] ?? '') !== '') {
            if ($category->parentId !== null) {
                throw new HttpNotFoundException(self::MISS);
            }

            $subcategory = $this->requireCategory($vars['subcategory']);

            if ($subcategory->parentId !== $category->id) {
                throw new HttpNotFoundException(self::MISS);
            }

            $parent = $category;
            $category = $subcategory;
        }

        $results = $this->search->browse(
            $category,
            $parent,
            null,
            $this->browseRequest($request),
        );

        return $this->view->render('layouts/app', [
            'title'       => $category->name . ($parent !== null ? ' — ' . $parent->name : '') . ' — Bulbula',
            'description' => 'Every ' . $category->name . ' listing on Bulbula'
                . ($parent !== null ? '' : ' in Addis Ababa') . '.',
            'content'     => $this->view->capture('pages/category', [
                'category' => $category,
                'parent'   => $parent,
                'results'  => $results,
            ]),
        ]);
    }

    /** /c/{category}/in/{area} — the highest-intent page in the product (§7). */
    public function categoryArea(Request $request, array $vars): Response
    {
        $category = $this->requireCategory($vars['category'] ?? '');
        $area = $this->requireArea($vars['area'] ?? '');
        $parent = $this->parentOf($category);

        $results = $this->search->browse(
            $category,
            $parent,
            $area,
            $this->browseRequest($request),
        );

        return $this->view->render('layouts/app', [
            'title'       => $category->name . ' in ' . $area->name . ' — Bulbula',
            'description' => $category->name . ' listings in ' . $area->name . ', Addis Ababa.',
            'content'     => $this->view->capture('pages/category_area', [
                'category' => $category,
                'parent'   => $parent,
                'area'     => $area,
                'results'  => $results,
            ]),
        ]);
    }

    /** /areas — the geography, grouped by Sub-city (§5). */
    public function areas(Request $request): Response
    {
        return $this->view->render('layouts/app', [
            'title'       => 'Areas — Bulbula',
            'description' => 'Every area of Addis Ababa Bulbula covers.',
            'content'     => $this->view->capture('pages/areas', [
                'catalogue' => $this->search->catalogue(),
            ]),
        ]);
    }

    /** /a/{area} — everything published in one place (§6). */
    public function area(Request $request, array $vars): Response
    {
        $area = $this->requireArea($vars['area'] ?? '');

        $results = $this->search->browse(
            null,
            null,
            $area,
            $this->browseRequest($request),
        );

        return $this->view->render('layouts/app', [
            'title'       => $area->name . ' — Bulbula',
            'description' => 'Every published business in ' . $area->name . ', Addis Ababa.',
            'content'     => $this->view->capture('pages/area', [
                'area'    => $area,
                'results' => $results,
            ]),
        ]);
    }

    private function requireCategory(string $slug): Category
    {
        return $this->search->categoryBySlug($slug)
            ?? throw new HttpNotFoundException(self::MISS);
    }

    private function requireArea(string $slug): Area
    {
        return $this->search->areaBySlug($slug)
            ?? throw new HttpNotFoundException(self::MISS);
    }

    private function parentOf(Category $category): ?Category
    {
        return $category->parentId !== null ? $this->search->categoryById($category->parentId) : null;
    }

    private function browseRequest(Request $request): SearchRequest
    {
        $sort = $request->trimmed('sort');

        return new SearchRequest(
            sort: in_array($sort, ['relevance', 'name'], true) ? $sort : 'relevance',
            page: max(1, (int) $request->trimmed('page')),
        );
    }
}
