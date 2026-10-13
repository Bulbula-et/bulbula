<?php

declare(strict_types=1);

/**
 * Area browse (public-web-ux §6): everything published in one place, with
 * the Categories present here as the narrowing navigation (IAR-14 — only
 * destinations that have published Listings).
 *
 * @var \Bulbula\Backend\Domain\Directory\Area         $area
 * @var \Bulbula\Backend\Services\Search\BrowseResults $results
 */
?>
<nav class="breadcrumb wrap" aria-label="Breadcrumb">
    <ol class="breadcrumb__list">
        <li><a href="<?= e(url('/')) ?>">Home</a></li>
        <li><a href="<?= e(url('/areas')) ?>">Areas</a></li>
        <li><span aria-current="page"><?= e($area->name) ?></span></li>
    </ol>
</nav>

<section class="wrap section">
    <h1 class="section__title"><?= e($area->name) ?></h1>

    <?php if ($results->categoryFacets !== []): ?>
        <nav class="facets" aria-label="Categories in this area">
            <h2 class="facets__title">What&rsquo;s here</h2>
            <ul class="chips">
                <?php foreach ($results->categoryFacets as $facet): ?>
                    <li>
                        <a class="chip chip--link" href="<?= e(url('/c/' . $facet['category']->slug->value . '/in/' . $area->slug->value)) ?>">
                            <?= e($facet['category']->name) ?> (<?= e((string) $facet['count']) ?>)
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>

    <?php if ($results->isEmpty()): ?>
        <p class="notice notice--info">
            Nothing is published in <?= e($area->name) ?> yet.
        </p>
        <ul class="links">
            <li><a href="<?= e(url('/areas')) ?>">Browse all Areas</a></li>
            <li><a href="<?= e(url('/categories')) ?>">Browse all Categories</a></li>
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
        $base = '/a/' . $area->slug->value;
        $params = [];
        require __DIR__ . '/../partials/pagination.php';
        ?>
    <?php endif; ?>
</section>
