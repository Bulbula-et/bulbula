<?php

declare(strict_types=1);

namespace Bulbula\Frontend\Controllers;

use Bulbula\Backend\Core\Request;
use Bulbula\Backend\Core\Response;
use Bulbula\Backend\Core\View;
use Bulbula\Backend\Services\Search\SearchRequest;
use Bulbula\Backend\Services\Search\SearchService;

/**
 * The search page (`/search?q=…` — IA §2.1).
 *
 * A controller does three things and nothing else: ask for what it needs,
 * hand the result to a view, return the response. Every matching rule, every
 * stage, the zero-result plan and the ranking live in `SearchService`; this
 * class only translates an address into a request (and an unknown *filter*
 * slug into simply-unmatchable, never into an error — an unknown *destination*
 * is a different thing and answers not-found).
 *
 * The page is not indexable (SEO-8): internal search results are thin and
 * duplicative; the browse pages are the discovery surfaces.
 */
final class SearchController
{
    public function __construct(
        private readonly View $view,
        private readonly SearchService $search,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = $request->trimmed('q');
        $categorySlug = $request->trimmed('category');
        $areaSlug = $request->trimmed('area');

        $searchRequest = new SearchRequest(
            query: $query,
            category: $categorySlug !== '' ? $this->search->categoryBySlug($categorySlug) : null,
            area: $areaSlug !== '' ? $this->search->areaBySlug($areaSlug) : null,
            openNow: $request->trimmed('open_now') === '1',
            minRating: is_numeric($request->trimmed('min_rating')) ? (float) $request->trimmed('min_rating') : null,
            verifiedOnly: $request->trimmed('verified') === '1',
            sort: in_array($request->trimmed('sort'), ['relevance', 'name'], true) ? $request->trimmed('sort') : 'relevance',
            page: max(1, (int) $request->trimmed('page')),
        );

        $results = $this->search->query($searchRequest);
        $catalogue = $this->search->catalogue();

        return $this->view->render('layouts/app', [
            'title'       => $query !== '' ? $query . ' — Search — Bulbula' : 'Search — Bulbula',
            'description' => 'Search Ethiopian businesses by name, category and area.',
            'robots'      => 'noindex, follow',
            'content'     => $this->view->capture('pages/search', [
                'request'   => $searchRequest,
                'results'   => $results,
                'catalogue' => $catalogue,
            ]),
        ]);
    }
}
