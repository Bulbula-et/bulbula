<?php

declare(strict_types=1);

use Bulbula\Backend\Domain\Directory\BranchState;
use Bulbula\Backend\Domain\Directory\Category;
use Bulbula\Backend\Services\Directory\ListingProfile;

/**
 * The public Business profile (public-web-ux §8; the same record on the wire
 * in api-spec §2.3).
 *
 * The information order is the one the UX document fixes: identity, trust,
 * open status, location, contact — and every block below renders only when it
 * has something true to say. Nothing here is a placeholder: no rating when
 * there are no Reviews (TR-63), no verification badge until a Business is
 * actually verified, no map frame without coordinates, and hours that are not
 * recorded read "Hours not confirmed", never "Closed" (TR-56, UXP-7.3).
 *
 * Every stored value is escaped. The three URLs this page builds are built
 * from slugs (already constrained to the URL grammar), from a template path,
 * or from two floats — no stored value reaches an attribute unchecked, and a
 * stored address cannot carry a scheme other than http(s) to begin with
 * (APP-2.11).
 *
 * @var ListingProfile $profile
 */
$business = $profile->business;
$primary  = $profile->primaryCategory;
$parent   = $primary !== null ? $profile->parentOf($primary) : null;
$area     = $profile->areaOf($profile->branches[0] ?? null);

/** The canonical path of a Category, full path for a Subcategory (IA §2.1). */
$categoryPath = static function (Category $category) use ($profile): string {
    $parentCategory = $profile->parentOf($category);

    return url('/c/' . ($parentCategory !== null ? $parentCategory->slug->value . '/' : '') . $category->slug->value);
};

/** A dialable link, or null when there is nothing to dial. */
$tel = static function (string $number): ?string {
    $digits = preg_replace('/[^0-9+]/', '', $number);

    return is_string($digits) && $digits !== '' ? 'tel:' . $digits : null;
};
?>
<nav class="breadcrumb wrap" aria-label="Breadcrumb">
    <ol class="breadcrumb__list">
        <li><a href="<?= e(url('/')) ?>">Home</a></li>
        <?php if ($parent !== null): ?>
            <li><a href="<?= e($categoryPath($parent)) ?>"><?= e($parent->name) ?></a></li>
        <?php endif; ?>
        <?php if ($primary !== null): ?>
            <li><a href="<?= e($categoryPath($primary)) ?>"><?= e($primary->name) ?></a></li>
        <?php endif; ?>
        <li aria-current="page"><?= e($business->name) ?></li>
    </ol>
</nav>

<article class="listing wrap section">
    <header class="listing__header">
        <h1 class="listing__title"><?= e($business->name) ?></h1>

        <?php if ($business->nameAm !== null): ?>
            <p class="listing__name-am" lang="am"><?= e($business->nameAm) ?></p>
        <?php endif; ?>

        <p class="listing__meta">
            <?php if ($primary !== null): ?>
                <a href="<?= e($categoryPath($primary)) ?>"><?= e($primary->name) ?></a>
            <?php endif; ?>
            <?php if ($area !== null): ?>
                <span aria-hidden="true"> · </span><a href="<?= e(url('/a/' . $area->slug->value)) ?>"><?= e($area->name) ?></a>
            <?php endif; ?>
            <span aria-hidden="true"> · </span><span class="listing__hours">Hours not confirmed</span>
        </p>
    </header>

    <?php if ($business->isClosed()): ?>
        <p class="notice notice--warning">
            This business is permanently closed. Its record stays here for reference.
        </p>
    <?php endif; ?>

    <?php if ($business->shortDescription !== null || $business->longDescription !== null): ?>
        <section class="listing__block" aria-labelledby="listing-about">
            <h2 class="section__title" id="listing-about">About</h2>

            <?php if ($business->shortDescription !== null): ?>
                <p class="section__lede"><?= e($business->shortDescription) ?></p>
            <?php endif; ?>

            <?php if ($business->longDescription !== null): ?>
                <p><?= nl2br(e($business->longDescription)) ?></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="listing__block" aria-labelledby="listing-locations">
        <h2 class="section__title" id="listing-locations">
            <?= count($profile->branches) > 1 ? 'Locations' : 'Location' ?>
        </h2>

        <?php foreach ($profile->branches as $branch): ?>
            <?php
            $branchArea    = $profile->areaOf($branch);
            $branchSubCity = $profile->subCityOf($branch);
            ?>
            <article class="branch">
                <?php if ($branch->label !== null): ?>
                    <h3 class="branch__title"><?= e($branch->label) ?></h3>
                <?php endif; ?>

                <?php if ($branch->isPermanentlyClosed()): ?>
                    <p class="branch__state">This location is permanently closed.</p>
                <?php elseif ($branch->state === BranchState::TemporarilyClosed): ?>
                    <p class="branch__state">This location is temporarily closed.</p>
                <?php endif; ?>

                <p class="branch__address">
                    <?php if ($branchArea !== null): ?>
                        <a href="<?= e(url('/a/' . $branchArea->slug->value)) ?>"><?= e($branchArea->name) ?></a>
                    <?php endif; ?>
                    <?php if ($branchSubCity !== null): ?>
                        <span aria-hidden="true"> · </span><?= e($branchSubCity->name) ?>
                        <?php if ($branchSubCity->city !== '' && $branchSubCity->city !== $branchSubCity->name): ?>
                            <span aria-hidden="true">, </span><?= e($branchSubCity->city) ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($branch->addressLine !== null): ?>
                        <br><?= e($branch->addressLine) ?>
                    <?php endif; ?>
                </p>

                <?php if ($branch->phone !== null || $branch->phoneSecondary !== null || $branch->email !== null): ?>
                    <ul class="contact">
                        <?php foreach ([$branch->phone, $branch->phoneSecondary] as $number): ?>
                            <?php if ($number !== null): ?>
                                <li>
                                    <?php $dial = $tel($number); ?>
                                    <?php if ($dial !== null): ?>
                                        <a href="<?= e($dial) ?>"><?= e($number) ?></a>
                                    <?php else: ?>
                                        <?= e($number) ?>
                                    <?php endif; ?>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <?php if ($branch->email !== null): ?>
                            <li><a href="<?= e('mailto:' . $branch->email) ?>"><?= e($branch->email) ?></a></li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($branch->latitude !== null && $branch->longitude !== null): ?>
                    <p class="branch__map">
                        <a href="<?= e('https://www.google.com/maps/search/?api=1&query=' . rawurlencode(sprintf('%.6F,%.6F', $branch->latitude, $branch->longitude))) ?>">
                            Open in maps
                        </a>
                    </p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>

    <?php
    $hasContact = $business->website !== null
        || $business->email !== null
        || (is_array($business->socialLinks) && $business->socialLinks !== []);
?>

    <?php if ($hasContact): ?>
        <section class="listing__block" aria-labelledby="listing-contact">
            <h2 class="section__title" id="listing-contact">Contact</h2>

            <ul class="contact">
                <?php if ($business->website !== null): ?>
                    <li><a href="<?= e($business->website) ?>" rel="noopener">Website</a></li>
                <?php endif; ?>

                <?php if ($business->email !== null): ?>
                    <li><a href="<?= e('mailto:' . $business->email) ?>"><?= e($business->email) ?></a></li>
                <?php endif; ?>

                <?php foreach (is_array($business->socialLinks) ? $business->socialLinks : [] as $platform => $address): ?>
                    <?php if (is_string($platform) && is_string($address)): ?>
                        <li><a href="<?= e($address) ?>" rel="noopener"><?= e(ucfirst($platform)) ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</article>
