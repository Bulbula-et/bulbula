<?php

declare(strict_types=1);

namespace Bulbula\Frontend\Controllers;

use Bulbula\Backend\Core\Request;
use Bulbula\Backend\Core\Response;
use Bulbula\Backend\Core\View;

/**
 * The homepage.
 *
 * A controller does three things and nothing else: ask for what it needs, hand
 * the result to a view, return the response. No queries, no rules, no output.
 */
final class HomeController
{
    public function __construct(private readonly View $view)
    {
    }

    public function index(Request $request): Response
    {
        return $this->view->render('layouts/app', [
            'title'       => 'Bulbula — find local businesses in Addis Ababa you can trust',
            'description' => 'Bulbula is a curated directory of Ethiopian businesses: '
                . 'verified facts, honest reviews, and no paid placement pretending to be a result.',
            'content'     => $this->view->capture('pages/home'),
        ]);
    }
}
