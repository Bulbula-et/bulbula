<?php

declare(strict_types=1);

namespace Bulbula\Backend\Services\Directory;

use Bulbula\Backend\Database\Connection;
use Bulbula\Backend\Domain\Directory\Branch;
use Bulbula\Backend\Domain\Directory\Business;
use Bulbula\Backend\Domain\Directory\Slug;
use Bulbula\Backend\Domain\Directory\WebAddress;
use Bulbula\Backend\Domain\Platform\ActorType;
use Bulbula\Backend\Exceptions\ConcurrencyConflictException;
use Bulbula\Backend\Exceptions\HttpNotFoundException;
use Bulbula\Backend\Exceptions\ValidationException;
use Bulbula\Backend\Repositories\Directory\AreaRepository;
use Bulbula\Backend\Repositories\Directory\BranchRepository;
use Bulbula\Backend\Repositories\Directory\BusinessRepository;
use Bulbula\Backend\Repositories\Directory\CategoryRepository;
use Bulbula\Backend\Repositories\Directory\SubCityRepository;
use Bulbula\Backend\Repositories\Platform\RedirectRepository;
use Bulbula\Backend\Services\Platform\AuditRecorder;
use Bulbula\Backend\Services\Search\SearchIndexer;
use Bulbula\Backend\Support\Clock;
use InvalidArgumentException;
use Throwable;

/**
 * The Directory's write side: creating a Business, editing it, classifying it,
 * adding a Branch, renaming its URL and closing it (build order §9 step 3).
 *
 * The rules this class exists to hold:
 *
 * - **A slug is unique, and changing one leaves a redirect** (IAR-3): links
 *   people shared must not rot, so `changeSlug` writes the redirect record in
 *   the same transaction as the rename.
 * - **Edits are versioned** (TR-57). A profile change states the version it
 *   read; a mismatch is a conflict, never a silent overwrite. Additive
 *   changes — a new Branch, a new classification — do **not** assert the
 *   version, because they cannot destroy anybody's typed input; they bump it,
 *   so a concurrent profile edit still fails loudly instead of landing
 *   unnoticed on top of them.
 * - **Closure is a state, never a delete** (TR-53), and it always carries a
 *   reason.
 * - **Every change is audited in its own transaction** (TR-08) — a change
 *   that could not be recorded does not happen.
 */
final class BusinessService
{
    /** The profile fields an operator may edit; anything else is ignored. */
    /** How many social links one Business may record, and how long a platform name may be. */
    private const MAX_SOCIAL_LINKS = 12;

    private const MAX_SOCIAL_PLATFORM = 32;

    private const EDITABLE = [
        'name', 'legal_name', 'name_am', 'short_description',
        'long_description', 'website', 'email', 'social_links',
    ];

    public function __construct(
        private readonly BusinessRepository $businesses,
        private readonly BranchRepository $branches,
        private readonly CategoryRepository $categories,
        private readonly AreaRepository $areas,
        private readonly SubCityRepository $subCities,
        private readonly RedirectRepository $redirects,
        private readonly AuditRecorder $audit,
        private readonly SearchIndexer $indexer,
        private readonly Connection $connection,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Create a Business in `draft`, and say which existing records look like it
     * (TR-55). The candidates are returned, not refused on.
     *
     * @throws ValidationException when the name is empty or the slug is taken
     */
    public function create(
        Slug $slug,
        string $name,
        ActorType $actorType,
        ?int $actorId,
        ?string $legalName = null,
        ?string $nameAm = null,
        ?string $email = null,
        ?string $phone = null,
    ): BusinessCreation {
        $name = trim($name);

        if ($name === '') {
            throw new ValidationException(['name' => 'A Business needs a name.']);
        }

        if ($this->businesses->slugExists($slug)) {
            throw new ValidationException(['slug' => 'That address is already in use.']);
        }

        $now = $this->clock->now();
        $business = null;

        $this->transact(function () use ($slug, $name, $legalName, $nameAm, $actorType, $actorId, $now, &$business): void {
            $business = $this->businesses->create($slug, $name, $legalName, $nameAm, $now, $actorId);

            $this->audit->record($actorType, $actorId, 'listing.created', 'listing', $business->id);
        });

        if (!$business instanceof Business) {
            throw new \RuntimeException('The creation transaction produced no Business.');
        }

        $candidates = array_values(array_filter(
            $this->businesses->duplicateCandidates($name, $email, $phone),
            static fn (Business $candidate): bool => $candidate->id !== $business->id,
        ));

        return new BusinessCreation($business, $candidates);
    }

    /**
     * Apply profile edits under the concurrency token (TR-57).
     *
     * @param array<string, mixed> $changes field => value; unknown fields are ignored
     *
     * @throws ConcurrencyConflictException when someone else edited the record first
     * @throws ValidationException          when a name is present and empty
     */
    public function updateProfile(
        int $businessId,
        array $changes,
        int $expectedVersion,
        ActorType $actorType,
        ?int $actorId,
    ): Business {
        $business = $this->require($businessId);
        $normalised = $this->normaliseChanges($changes);
        $now = $this->clock->now();

        $this->transact(function () use ($businessId, $normalised, $expectedVersion, $actorType, $actorId, $now): void {
            $applied = $this->businesses->updateProfile($businessId, $normalised, $expectedVersion, $now, $actorId);

            if (!$applied) {
                throw new ConcurrencyConflictException($expectedVersion);
            }

            // SD-2: the search document moves with the edit, in the same
            // transaction.
            $this->indexer->syncFor($businessId);

            $this->audit->record(
                $actorType,
                $actorId,
                'listing.updated',
                'listing',
                $businessId,
                $normalised === [] ? null : implode(', ', array_keys($normalised)),
            );
        });

        return $this->require($businessId);
    }

    /**
     * Rename the public address, keeping the old one alive as a redirect
     * (IAR-3, SEO-4). A no-op when the slug is unchanged.
     */
    public function changeSlug(
        int $businessId,
        Slug $slug,
        int $expectedVersion,
        ActorType $actorType,
        ?int $actorId,
    ): Business {
        $business = $this->require($businessId);

        if ($business->slug->equals($slug)) {
            return $business;
        }

        if ($this->businesses->slugExists($slug)) {
            throw new ValidationException(['slug' => 'That address is already in use.']);
        }

        $previous = $business->slug;
        $now = $this->clock->now();

        $this->transact(function () use ($businessId, $slug, $previous, $expectedVersion, $actorType, $actorId, $now): void {
            $applied = $this->businesses->changeSlug($businessId, $slug, $expectedVersion, $now, $actorId);

            if (!$applied) {
                throw new ConcurrencyConflictException($expectedVersion);
            }

            $this->redirects->record(
                '/b/' . $previous->value,
                '/b/' . $slug->value,
                'slug_change',
                $now,
            );

            $this->indexer->syncFor($businessId);

            $this->audit->record(
                $actorType,
                $actorId,
                'listing.slug_changed',
                'listing',
                $businessId,
                $previous->value . ' → ' . $slug->value,
            );
        });

        return $this->require($businessId);
    }

    /**
     * Add a Branch. The first Branch of a Business is its primary one (D-03) —
     * exactly one Branch is primary, always.
     *
     * @throws HttpNotFoundException when the Area or Sub-city does not exist
     * @throws ValidationException   when the Area is not in the Sub-city given
     */
    public function addBranch(
        int $businessId,
        int $areaId,
        int $subCityId,
        ActorType $actorType,
        ?int $actorId,
        ?string $label = null,
        ?string $addressLine = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $phone = null,
        ?string $phoneSecondary = null,
        ?string $email = null,
    ): Branch {
        $business = $this->require($businessId);

        $area = $this->areas->findById($areaId)
            ?? throw new HttpNotFoundException('There is no Area with that id.');

        $subCity = $this->subCities->findById($subCityId)
            ?? throw new HttpNotFoundException('There is no Sub-city with that id.');

        if ($area->subCityId !== $subCity->id) {
            throw new ValidationException(['sub_city_id' => 'That Area does not belong to that Sub-city.']);
        }

        $isPrimary = $this->branches->countForBusiness($businessId) === 0;
        $now = $this->clock->now();
        $branch = null;

        $this->transact(function () use (
            $businessId,
            $areaId,
            $subCityId,
            $label,
            $addressLine,
            $latitude,
            $longitude,
            $phone,
            $phoneSecondary,
            $email,
            $isPrimary,
            $actorType,
            $actorId,
            $business,
            $now,
            &$branch,
        ): void {
            $branch = $this->branches->create(
                $businessId,
                $areaId,
                $subCityId,
                $label,
                $addressLine,
                $latitude,
                $longitude,
                $phone,
                $phoneSecondary,
                $email,
                $isPrimary,
                $now,
            );

            $this->businesses->touch($businessId, $now, $actorId);

            $this->indexer->syncFor($businessId);

            $this->audit->record(
                $actorType,
                $actorId,
                'listing.branch_added',
                'listing',
                $businessId,
                'branch ' . ($business->slug->value) . ($isPrimary ? ' (primary)' : ''),
            );
        });

        if (!$branch instanceof Branch) {
            throw new \RuntimeException('The branch transaction produced no Branch.');
        }

        return $branch;
    }

    /**
     * Classify a Listing: exactly one primary Category, any number of
     * secondaries (D-57). Assigning a new primary demotes the previous one, so
     * the invariant holds after every call.
     */
    public function classify(
        int $businessId,
        int $categoryId,
        bool $isPrimary,
        ActorType $actorType,
        ?int $actorId,
    ): void {
        $this->require($businessId);

        $category = $this->categories->findById($categoryId)
            ?? throw new HttpNotFoundException('There is no Category with that id.');

        if ($this->businesses->hasCategory($businessId, $categoryId)) {
            throw new ValidationException(['category_id' => 'That Category is already on this Listing.']);
        }

        $now = $this->clock->now();

        $this->transact(function () use ($businessId, $categoryId, $isPrimary, $category, $actorType, $actorId, $now): void {
            if ($isPrimary) {
                $this->businesses->clearPrimaryCategories($businessId);
            }

            $this->businesses->assignCategory($businessId, $categoryId, $isPrimary, $now);
            $this->businesses->touch($businessId, $now, $actorId);

            $this->indexer->syncFor($businessId);

            $this->audit->record(
                $actorType,
                $actorId,
                'listing.classified',
                'listing',
                $businessId,
                $category->name . ($isPrimary ? ' (primary)' : ' (secondary)'),
            );
        });
    }

    /**
     * Permanent closure: a state on the record, never a deletion (TR-53). The
     * Listing, its URL and its history survive, and the reason is recorded.
     */
    public function close(int $businessId, string $reason, ActorType $actorType, ?int $actorId): Business
    {
        $business = $this->require($businessId);

        if ($business->isClosed()) {
            throw new ValidationException(['state' => 'This Listing is already closed.']);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Closing a Listing always carries a reason.');
        }

        $now = $this->clock->now();

        $this->transact(function () use ($businessId, $reason, $actorType, $actorId, $now): void {
            $this->businesses->markClosed($businessId, $reason, $now, $actorId);

            // SD-2: closure is a state, not a removal — the document keeps the
            // Listing findable and records its `closed` status (MT-2, PR-2).
            $this->indexer->syncFor($businessId);

            $this->audit->record($actorType, $actorId, 'listing.closed', 'listing', $businessId, $reason);
        });

        return $this->require($businessId);
    }

    private function require(int $businessId): Business
    {
        return $this->businesses->findById($businessId)
            ?? throw new HttpNotFoundException('There is no Listing with that id.');
    }

    /**
     * Trim text, encode the social-links map, and refuse a name that would
     * erase one. Unknown fields are ignored rather than passed through: a
     * caller cannot edit `version` or `status` by naming them.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, string|null>
     */
    private function normaliseChanges(array $changes): array
    {
        $normalised = [];

        foreach (self::EDITABLE as $field) {
            if (!array_key_exists($field, $changes)) {
                continue;
            }

            $value = $changes[$field];

            if ($field === 'social_links') {
                $normalised[$field] = $this->encodeSocialLinks($value);

                continue;
            }

            if ($value === null) {
                $normalised[$field] = null;

                continue;
            }

            if (!is_string($value)) {
                throw new InvalidArgumentException('Field [' . $field . '] must be text.');
            }

            $trimmed = trim($value);

            if ($field === 'name' && $trimmed === '') {
                throw new ValidationException(['name' => 'A Business needs a name.']);
            }

            if ($field === 'website' && $trimmed !== '') {
                $normalised[$field] = (string) $this->webAddress($trimmed, 'website');

                continue;
            }

            $normalised[$field] = $trimmed === '' ? null : $trimmed;
        }

        return $normalised;
    }

    /** @return string|null JSON for the storage layer, or null for "none recorded" */
    private function encodeSocialLinks(mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('Social links are a map of platform to address.');
        }

        if (count($value) > self::MAX_SOCIAL_LINKS) {
            throw new ValidationException([
                'social_links' => 'At most ' . self::MAX_SOCIAL_LINKS . ' social links are recorded.',
            ]);
        }

        $links = [];

        foreach ($value as $platform => $address) {
            if (!is_string($platform) || !is_string($address)) {
                throw new InvalidArgumentException('Social links are a map of platform to address.');
            }

            $platform = trim($platform);

            if ($platform === '' || strlen($platform) > self::MAX_SOCIAL_PLATFORM) {
                throw new ValidationException([
                    'social_links' => 'A social platform name is 1 to ' . self::MAX_SOCIAL_PLATFORM . ' characters.',
                ]);
            }

            $links[$platform] = (string) $this->webAddress($address, 'social_links');
        }

        return json_encode($links, JSON_THROW_ON_ERROR);
    }

    /**
     * A stored address must be `http` or `https` before it is ever an `href`
     * (APP-2.11, APP-2.12). Escaping protects the attribute; only this
     * protects the scheme.
     *
     * @throws ValidationException when the value is not an http(s) address
     */
    private function webAddress(string $value, string $field): WebAddress
    {
        try {
            return WebAddress::fromString($value);
        } catch (InvalidArgumentException $refusal) {
            // The validator's message is safe to show; the offending value is
            // not echoed back into it (APP-2.8).
            throw new ValidationException([$field => $refusal->getMessage()]);
        }
    }

    /**
     * The change and its audit entry commit together, or neither does (TR-08).
     *
     * @param callable(): void $work
     */
    private function transact(callable $work): void
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $work();
            $pdo->commit();
        } catch (Throwable $failure) {
            $pdo->rollBack();

            throw $failure;
        }
    }
}
