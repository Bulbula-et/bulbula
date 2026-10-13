<?php

declare(strict_types=1);

use Bulbula\Backend\Core\Router;
use Bulbula\Backend\Domain\Directory\Slug;

/**
 * Web surface: server-rendered pages for people.
 *
 * A route file is a table of contents: paths and handlers, nothing else. When
 * dynamic paths arrive they will constrain every segment with a shared slug
 * pattern, so a reserved word can never be reached through a wildcard and a
 * URL can never carry a payload.
 *
 * Routes are added as their feature slice lands — a registered path with no
 * implementation behind it is a promise the application cannot keep. The
 * planned paths come from the build order in
 * docs/70-engineering/project-structure-v2.md §9:
 *
 *   now     /sign-in · POST /sign-in/code · POST /sign-in/verify · POST /sign-out
 *           /b/{business}
 *           /search?q= · /categories · /c/{category} · /c/{category}/{subcategory}
 *           /c/{category}/in/{area} · /areas · /a/{area} · /contact
 *   later   /b/{business}/reviews|photos|hours|report · POST /reviews · POST /reports
 *           /about · /privacy · /terms · /how-ranking-works
 *
 * `/in/` is a reserved word in the category path grammar (IA §2.1): the
 * Category × Area form `/c/{category}/in/{area}` is registered before the
 * Subcategory form so the literal segment cannot be shadowed, and a
 * Subcategory may not be called `in`.
 */
return static function (Router $router): void {
    $router->get('/', ['Bulbula\Frontend\Controllers\HomeController', 'index']);
    $router->get('/search', ['Bulbula\Frontend\Controllers\SearchController', 'index']);

    // The public Business profile (IA §2.1). The segment carries the slug
    // grammar itself — `Slug::ROUTE_PATTERN` — so a malformed address never
    // reaches a handler, and the grammar cannot drift from the one the
    // database stores.
    $router->get(
        '/b/{business:' . Slug::ROUTE_PATTERN . '}',
        ['Bulbula\Frontend\Controllers\ListingController', 'show'],
    );

    // Discovery (IA §2.1): the taxonomy and the geography. Most specific
    // first — `/c/{category}/in/{area}` before `/c/{category}/{subcategory}`
    // before `/c/{category}` — so each address keeps one meaning.
    $browse = 'Bulbula\Frontend\Controllers\BrowseController';

    $router->get('/categories', [$browse, 'categories']);
    $router->get('/areas', [$browse, 'areas']);
    $router->get(
        '/c/{category:' . Slug::ROUTE_PATTERN . '}/in/{area:' . Slug::ROUTE_PATTERN . '}',
        [$browse, 'categoryArea'],
    );
    $router->get(
        '/c/{category:' . Slug::ROUTE_PATTERN . '}/{subcategory:' . Slug::ROUTE_PATTERN . '}',
        [$browse, 'category'],
    );
    $router->get('/c/{category:' . Slug::ROUTE_PATTERN . '}', [$browse, 'category']);
    $router->get('/a/{area:' . Slug::ROUTE_PATTERN . '}', [$browse, 'area']);

    // The help route every page carries (IAR-11) and the correction channel
    // of the zero-result screen (C-15).
    $router->get('/contact', ['Bulbula\Frontend\Controllers\ContactController', 'show']);

    // Sign-in (C-31, C-32). Every state-changing path is a POST and carries a
    // CSRF token; `GET /sign-in` only issues one.
    $signIn = 'Bulbula\Frontend\Controllers\SignInController';

    $router->get('/sign-in', [$signIn, 'show']);
    $router->post('/sign-in/code', [$signIn, 'requestCode']);
    $router->post('/sign-in/verify', [$signIn, 'verify']);

    // Google's redirect flow posts the credential here (C-30). It is a POST
    // because Google sends one, and the credential is verified server-side.
    $router->post('/sign-in/google', [$signIn, 'google']);
    $router->post('/sign-out', [$signIn, 'signOut']);
};
