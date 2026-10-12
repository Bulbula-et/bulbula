<?php

declare(strict_types=1);

namespace Bulbula\Frontend\Controllers;

use Bulbula\Backend\Core\Request;
use Bulbula\Backend\Core\Response;
use Bulbula\Backend\Core\View;
use Bulbula\Backend\Domain\Directory\Slug;
use Bulbula\Backend\Exceptions\HttpNotFoundException;
use Bulbula\Backend\Services\Directory\ListingProfile;
use Bulbula\Backend\Services\Directory\PublicListingService;

/**
 * A Business profile, as a person sees it (`/b/{business}` — IA §2.1).
 *
 * A controller does three things and nothing else: ask for what it needs, hand
 * the result to a view, return the response. Everything that decides *what*
 * may be seen lives in `PublicListingService`; everything that decides how it
 * looks lives in the template.
 *
 * Three answers are possible, and they are the whole public contract of this
 * address: the profile; a permanent redirect to a newer address when the slug
 * moved (IAR-3); or a plain not-found — the same one for a draft, an
 * unpublished record and a slug nobody ever created, so the address never
 * reveals which Businesses exist privately (C-08, TR-35).
 */
final class ListingController
{
    /**
     * One message for every miss, so the response cannot be used as an oracle
     * for unpublished records, and the address a visitor typed is never
     * echoed back (APP-2.8).
     */
    private const MISS = 'No published listing answers that address.';

    public function __construct(
        private readonly View $view,
        private readonly PublicListingService $listings,
    ) {
    }

    /** @param array<string, string> $vars */
    public function show(Request $request, array $vars): Response
    {
        $slug = Slug::tryFromString($vars['business'] ?? '');

        if ($slug === null) {
            throw new HttpNotFoundException(self::MISS);
        }

        $profile = $this->listings->find($slug);

        if ($profile === null) {
            $target = $this->listings->permanentRedirectFor('/b/' . $slug->value);

            if ($target !== null) {
                // 301: the move is permanent, and the recorded target is the
                // only place it is written down (IAR-3).
                return Response::redirect($target, 301);
            }

            throw new HttpNotFoundException(self::MISS);
        }

        return $this->view->render('layouts/app', [
            'title'       => $this->title($profile),
            'description' => $this->description($profile),
            'content'     => $this->view->capture('pages/listing', ['profile' => $profile]),
        ]);
    }

    private function title(ListingProfile $profile): string
    {
        $trail = $this->categoryAndArea($profile);

        return $profile->business->name
            . ($trail !== '' ? ' — ' . $trail : '')
            . ' — Bulbula';
    }

    /**
     * A description a search engine can tell apart from the next one: the
     * Business's own words when it has them, and otherwise the facts this page
     * is built from (public-web-ux §8, SEO notes).
     */
    private function description(ListingProfile $profile): string
    {
        $business = $profile->business;

        if ($business->shortDescription !== null) {
            return $business->shortDescription;
        }

        $trail = $this->categoryAndArea($profile);

        return 'Contact details, location and opening information for ' . $business->name
            . ($trail !== '' ? ' — ' . $trail : '') . '.';
    }

    /** "Pharmacies in Bole Bulbula" — what this profile is, in four words. */
    private function categoryAndArea(ListingProfile $profile): string
    {
        $category = $profile->primaryCategory;
        $area     = $profile->areaOf($profile->branches[0] ?? null);

        return trim(
            ($category !== null ? $category->name : '')
            . ($area !== null ? ' in ' . $area->name : ''),
        );
    }
}
