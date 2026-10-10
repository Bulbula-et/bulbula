<?php

declare(strict_types=1);

namespace Bulbula\Frontend\Controllers;

use Bulbula\Backend\Core\Request;
use Bulbula\Backend\Core\Response;
use Bulbula\Backend\Core\View;

/**
 * The search page.
 *
 * Listing search arrives with step 4 of the build order (see
 * docs/70-engineering/project-structure-v2.md §9). Until the directory has
 * published listings, this page says so plainly rather than pretending to
 * search and returning an empty shelf.
 */
final class SearchController
{
    public function __construct(private readonly View $view)
    {
    }

    public function index(Request $request): Response
    {
        $term = $request->trimmed('q');

        return $this->view->render('layouts/app', [
            'title'       => 'Search — Bulbula',
            'description' => 'Search Ethiopian businesses by name, category and area.',
            'content'     => $this->view->capture('pages/search', [
                'term' => $term !== '' ? $term : null,
            ]),
        ]);
    }
}
