<?php

declare(strict_types=1);

/**
 * Category × Area (public-web-ux §7) — the highest-intent discovery page:
 * this kind of business, in this place. One canonical URL (IAR-5).
 *
 * @var \Bulbula\Backend\Domain\Directory\Category      $category
 * @var \Bulbula\Backend\Domain\Directory\Category|null $parent
 * @var \Bulbula\Backend\Domain\Directory\Area          $area
 * @var \Bulbula\Backend\Services\Search\BrowseResults  $results
 */
?>
<nav class="breadcrumb wrap" aria-label="Breadcrumb">
    <ol class="breadcrumb__list">
        <li><a href="<?= e(url('/')) ?>">Home</a></li>
        <li><a href="<?= e(url('/categories')) ?>">Categories</a></li>
        <?php if ($parent !== null): ?>
            <li><a href="<?= e(url('/c/' . $parent->slug->value)) ?>"><?= e($parent->name) ?></a></li>
        <?php endif; ?>
        <li><a href="<?= e(url('/c/' . ($parent !== null ? $parent->slug->value . '/' : '') . $category->slug->value)) ?>"><?= e($category->name) ?></a></li>
        <li><span aria-current="page">in <?= e($area->name) ?></span></li>
    </ol>
</nav>

<section class="wrap section">
    <h1 class="section__title"><?= e($category->name) ?> in <?= e($area->name) ?></h1>

    <?php if ($results->isEmpty()): ?>
        <p class="notice notice--info">
            Nothing is published under <?= e($category->name) ?> in <?= e($area->name) ?> yet.
        </p>
        <h2 class="section__title">Nearest alternatives</h2>
        <ul class="links">
            <li>
                <a href="<?= e(url('/c/' . ($parent !== null ? $parent->slug->value . '/' : '') . $category->slug->value)) ?>">
                    <?= e($category->name) ?> in all areas
                </a>
            </li>
            <li>
                <a href="<?= e(url('/a/' . $area->slug->value)) ?>">
                    Everything in <?= e($area->name) ?>
                </a>
            </li>
            <?php foreach ($results->categoryFacets as $facet): ?>
                <li>
                    <a href="<?= e(url('/c/' . $facet['category']->slug->value . '/in/' . $area->slug->value)) ?>">
                        <?= e($facet['category']->name) ?> in <?= e($area->name) ?> (<?= e((string) $facet['count']) ?>)
                    </a>
                </li>
            <?php endforeach; ?>
            <li><a href="<?= e(url('/search')) ?>">Search instead</a></li>
        </ul>
        <p class="section__lede">
            Something missing? <a href="<?= e(url('/contact')) ?>">Tell us what&rsquo;s missing</a>.
        </p>
    <?php else: ?>
        <p class="results-head__count" role="status">
            <?= e((string) $results->total) ?>&nbsp;<?= $results->total === 1 ? 'listing' : 'listings' ?>
        </p>

        <ul class="cards">
            <?php foreach ($results->items as $item) {
                require __DIR__ . '/../partials/business_card.php';
            } ?>
        </ul>

        <?php
        $total = $results->total;
        $page = $results->page;
        $pageSize = $results->pageSize;
        $base = '/c/' . ($parent !== null ? $parent->slug->value . '/' : '') . $category->slug->value . '/in/' . $area->slug->value;
        $params = [];
        require __DIR__ . '/../partials/pagination.php';
        ?>

        <p class="section__lede">
            Widen to <a href="<?= e(url('/c/' . ($parent !== null ? $parent->slug->value . '/' : '') . $category->slug->value)) ?>"><?= e($category->name) ?> everywhere</a>
            or <a href="<?= e(url('/a/' . $area->slug->value)) ?>">everything in <?= e($area->name) ?></a>.
        </p>
    <?php endif; ?>
</section>
