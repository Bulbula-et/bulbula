<?php

declare(strict_types=1);

namespace Bulbula\Frontend\Controllers;

use Bulbula\Backend\Core\Request;
use Bulbula\Backend\Core\Response;
use Bulbula\Backend\Core\View;

/**
 * `/contact` — the help route every page carries (IAR-11) and the correction
 * channel of the zero-result screen ("tell us what's missing", C-15,
 * UFL-A7.6).
 *
 * The page states what is true today and nothing more. The contact details
 * come from configuration when the team sets them; until then the page says
 * plainly that the correction form is arriving with the reports slice —
 * an honest present beats a promised channel that does not answer.
 */
final class ContactController
{
    public function __construct(
        private readonly View $view,
        private readonly string $contactEmail = '',
    ) {
    }

    public function show(Request $request): Response
    {
        return $this->view->render('layouts/app', [
            'title'       => 'Contact — Bulbula',
            'description' => 'How to reach the Bulbula team: corrections, missing businesses, and questions.',
            'content'     => $this->view->capture('pages/contact', [
                'contactEmail' => $this->contactEmail,
            ]),
        ]);
    }
}
