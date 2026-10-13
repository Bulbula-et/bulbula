<?php

declare(strict_types=1);

/**
 * One result card (component-spec §11) — the repeated unit of every list.
 *
 * A field that is missing is omitted entirely, with its space: never "N/A",
 * never a greyed placeholder (C-08). Ratings and the Save control arrive
 * with their modules; open status shows "Hours not confirmed" until the hours
 * model exists (PR-5, D-04) and a closed Business says so plainly (PR-2).
 *
 * @var \Bulbula\Backend\Services\Search\SearchResultItem $item
 */
$profile = $item->profile;
$business = $profile->business;
$primaryCategory = $profile->primaryCategory;
$parentCategory = $primaryCategory !== null ? $profile->parentOf($primaryCategory) : null;
$branch = $profile->branches[0] ?? null;
$area = $profile->areaOf($branch);
?>
<li class="card">
    <article class="card__inner">
        <h3 class="card__title">
            <a href="<?= e(url('/b/' . $business->slug->value)) ?>"><?= e($business->name) ?></a>
        </h3>

        <?php if ($business->nameAm !== null): ?>
            <p class="card__name-am" lang="am"><?= e($business->nameAm) ?></p>
        <?php endif; ?>

        <p class="card__meta">
            <?php if ($primaryCategory !== null): ?>
                <a href="<?= e(url('/c/' . ($parentCategory !== null ? $parentCategory->slug->value . '/' : '') . $primaryCategory->slug->value)) ?>"><?= e($primaryCategory->name) ?></a>
            <?php endif; ?>
            <?php if ($area !== null): ?>
                <span aria-hidden="true"> · </span><a href="<?= e(url('/a/' . $area->slug->value)) ?>"><?= e($area->name) ?></a>
            <?php endif; ?>
            <span aria-hidden="true"> · </span><span class="card__hours">Hours not confirmed</span>
        </p>

        <?php if ($business->isClosed()): ?>
            <p class="card__closed">Permanently closed</p>
        <?php endif; ?>

        <?php if ($business->shortDescription !== null && $business->shortDescription !== ''): ?>
            <p class="card__body"><?= e($business->shortDescription) ?></p>
        <?php endif; ?>
    </article>
</li>
