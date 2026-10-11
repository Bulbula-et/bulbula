<?php

declare(strict_types=1);

use Bulbula\Backend\Core\Router;

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
 *   step 3  /b/{business} · /b/{business}/{listing}
 *           /b/{business}/{listing}/reviews|photos|hours|report
 *           POST /reviews · POST /reports
 *   step 4  /search?q= · /categories · /c/{category} · /areas · /a/{area}
 *   step 6  /about · /privacy · /terms · /contact · /how-ranking-works
 */
return static function (Router $router): void {
    $router->get('/', ['Bulbula\Frontend\Controllers\HomeController', 'index']);
    $router->get('/search', ['Bulbula\Frontend\Controllers\SearchController', 'index']);

    // Sign-in (C-31, C-32). Every state-changing path is a POST and carries a
    // CSRF token; `GET /sign-in` only issues one.
    $signIn = 'Bulbula\Frontend\Controllers\SignInController';

    $router->get('/sign-in', [$signIn, 'show']);
    $router->post('/sign-in/code', [$signIn, 'requestCode']);
    $router->post('/sign-in/verify', [$signIn, 'verify']);
    $router->post('/sign-out', [$signIn, 'signOut']);
};
