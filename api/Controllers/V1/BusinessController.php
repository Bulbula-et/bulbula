<?php

declare(strict_types=1);

namespace Bulbula\Api\Controllers\V1;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Core\Request;
use Bulbula\Backend\Core\Response;
use Bulbula\Backend\Domain\Directory\Branch;
use Bulbula\Backend\Domain\Directory\Category;
use Bulbula\Backend\Domain\Directory\Slug;
use Bulbula\Backend\Exceptions\HttpNotFoundException;
use Bulbula\Backend\Services\Directory\ListingProfile;
use Bulbula\Backend\Services\Directory\PublicListingService;

/**
 * `GET /api/v1/businesses/{slug}` — the public profile, for the Mini App and
 * for anyone else (api-spec §2.3, C-08).
 *
 * The rules the shape obeys, because a public response is a promise:
 *
 * - **PR-1** — an unpublished or removed Listing answers `404`, and the same
 *   `404` answers a slug that never existed.
 * - **PR-2** — a permanently closed Business answers `200` with
 *   `status: "closed"`: the URL and the history survive.
 * - **PR-3** — data that is not recorded is **omitted**, never sent as an
 *   empty string or an "unknown" placeholder.
 * - **PR-4** — contact actions appear only where a contact point exists.
 * - **PR-5** — `open_status` is `"unknown"` while hours are not confirmed,
 *   never `"closed"`.
 * - **PR-6** — location is the Area, the Sub-city, the address and the
 *   coordinates; a client that cannot draw a map still has the address.
 * - **TR-25, DO-4** — no internal numeric id, ever, and no provenance,
 *   permission, verification or moderation detail.
 */
final class BusinessController
{
    /** One message for every miss — see the web controller for why (TR-35). */
    private const MISS = 'No published listing answers that address.';

    public function __construct(
        private readonly PublicListingService $listings,
        private readonly Config $config,
    ) {
    }

    /** @param array<string, string> $vars */
    public function show(Request $request, array $vars): Response
    {
        return $this->cacheable($request, ['data' => $this->payload($this->profileOr404($vars['slug'] ?? ''))]);
    }

    /**
     * `GET /api/v1/businesses/{slug}/branches` — the Branches the profile
     * already carries, on their own (api-spec §2.3, C-08, C-09).
     *
     * A client that wants locations and contact actions — a map view, a
     * "call this branch" button — need not fetch and discard a whole profile.
     * The shape is the profile's Branch object, unchanged, so the two cannot
     * drift.
     *
     * @param array<string, string> $vars
     */
    public function branches(Request $request, array $vars): Response
    {
        $profile = $this->profileOr404($vars['slug'] ?? '');

        return $this->cacheable($request, [
            'data' => array_map(
                fn (Branch $branch): array => $this->branchPayload($branch, $profile),
                $profile->branches,
            ),
        ]);
    }

    /**
     * One lookup and one answer for every miss: a slug that never existed, a
     * draft, and an unpublished Listing are indistinguishable here (PR-1,
     * C-08, TR-35).
     */
    private function profileOr404(string $slug): ListingProfile
    {
        $parsed  = Slug::tryFromString($slug);
        $profile = $parsed !== null ? $this->listings->find($parsed) : null;

        if ($profile === null) {
            throw new HttpNotFoundException(self::MISS);
        }

        return $profile;
    }

    /** @return array<string, mixed> */
    private function payload(ListingProfile $profile): array
    {
        $business = $profile->business;

        $payload = [
            'slug'        => $business->slug->value,
            'name'        => $business->name,
            'status'      => $business->state->value,
            'open_status' => 'unknown',
            'categories'  => array_map(
                fn (Category $category): array => $this->categoryPayload($category, $profile),
                $profile->categories,
            ),
            'branches'    => array_map(
                fn (Branch $branch): array => $this->branchPayload($branch, $profile),
                $profile->branches,
            ),
        ];

        if ($business->nameAm !== null) {
            $payload['name_am'] = $business->nameAm;
        }

        $description = [];

        if ($business->shortDescription !== null) {
            $description['short'] = $business->shortDescription;
        }

        if ($business->longDescription !== null) {
            $description['long'] = $business->longDescription;
        }

        if ($description !== []) {
            $payload['description'] = $description;
        }

        if ($business->website !== null) {
            $payload['website'] = $business->website;
        }

        if ($business->email !== null) {
            $payload['email'] = $business->email;
        }

        if (is_array($business->socialLinks) && $business->socialLinks !== []) {
            $payload['social_links'] = $business->socialLinks;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function categoryPayload(Category $category, ListingProfile $profile): array
    {
        $payload = [
            'slug'    => $category->slug->value,
            'name'    => $category->name,
            'primary' => $category->id === $profile->primaryCategory?->id,
        ];

        if ($category->nameAm !== null) {
            $payload['name_am'] = $category->nameAm;
        }

        $parent = $profile->parentOf($category);

        if ($parent !== null) {
            $payload['parent'] = ['slug' => $parent->slug->value, 'name' => $parent->name];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function branchPayload(Branch $branch, ListingProfile $profile): array
    {
        $payload = [
            'primary' => $branch->isPrimary,
            'status'  => $branch->state->value,
        ];

        if ($branch->label !== null) {
            $payload['label'] = $branch->label;
        }

        $area = $profile->areaOf($branch);

        if ($area !== null) {
            $areaPayload = ['slug' => $area->slug->value, 'name' => $area->name];

            if ($area->nameAm !== null) {
                $areaPayload['name_am'] = $area->nameAm;
            }

            $payload['area'] = $areaPayload;
        }

        $subCity = $profile->subCityOf($branch);

        if ($subCity !== null) {
            $subCityPayload = ['slug' => $subCity->slug->value, 'name' => $subCity->name];

            if ($subCity->nameAm !== null) {
                $subCityPayload['name_am'] = $subCity->nameAm;
            }

            $payload['sub_city'] = $subCityPayload;
        }

        if ($branch->addressLine !== null) {
            $payload['address'] = $branch->addressLine;
        }

        if ($branch->latitude !== null && $branch->longitude !== null) {
            $payload['coordinates'] = ['latitude' => $branch->latitude, 'longitude' => $branch->longitude];
        }

        $contact = [];

        if ($branch->phone !== null) {
            $contact['phone'] = $branch->phone;
        }

        if ($branch->phoneSecondary !== null) {
            $contact['phone_secondary'] = $branch->phoneSecondary;
        }

        if ($branch->email !== null) {
            $contact['email'] = $branch->email;
        }

        if ($contact !== []) {
            $payload['contact'] = $contact;
        }

        return $payload;
    }

    /**
     * GET /api/v1/businesses/nearby — businesses near a given location.
     *
     * Takes latitude and longitude as query parameters.
     * Returns up to 10 results (max 50).
     * Each item is a shortened profile (no heavy text fields).
     *
     * @param array<string, string> $vars
     */
    public function nearby(Request $request, array $vars): Response
    {
        $lat = (float) $request->query('latitude');
        $lng = (float) $request->query('longitude');
        $limit = (int) ($request->query('limit') ?? 10);
        $limit = min(max($limit, 1), 50);

        if ($lat === 0 || $lng === 0) {
            return $this->cacheable($request, ['data' => []]);
        }

        $businesses = $this->listings->findNearby($lat, $lng, $limit);

        $items = array_map(fn (Bulbula\Backend\Domain\Directory\Business $business): array => [
            'slug' => $business->slug->value,
            'name' => $business->name,
            'status' => $business->state->value,
            'open_status' => 'unknown',
        ], $businesses);

        return $this->cacheable($request, ['data' => $items]);
    }


    /**
     * Public read caching (api-spec §1.8): a content-derived `ETag` (HC-4) so a
     * rebuild that changes nothing does not invalidate caches, `Cache-Control`
     * from configuration rather than constants (TR-196), and a `304` when the
     * client already holds this exact representation.
     *
     * `Vary` is not set because nothing in this representation varies by
     * request header yet; when Amharic output arrives, this response must vary
     * by `Accept-Language`.
     *
     * @param array<string, mixed> $payload
     */
    private function cacheable(Request $request, array $payload): Response
    {
        $json = Response::json($payload);
        $etag = '"' . hash('sha256', $json->body()) . '"';

        $cacheControl = sprintf(
            'public, max-age=%d, stale-while-revalidate=%d',
            $this->config->int('cache.public_read.max_age', 300),
            $this->config->int('cache.public_read.stale_while_revalidate', 3600),
        );

        if ($this->matches($request->header('if-none-match'), $etag)) {
            return new Response('', 304, ['Cache-Control' => $cacheControl, 'ETag' => $etag]);
        }

        return $json
            ->withHeader('Cache-Control', $cacheControl)
            ->withHeader('ETag', $etag);
    }

    /** `If-None-Match` compares weakly: `W/"…"` matches, and `*` matches anything held. */
    private function matches(?string $header, string $etag): bool
    {
        if ($header === null) {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === '*' || $candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
