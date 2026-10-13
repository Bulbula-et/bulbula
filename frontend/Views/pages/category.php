<?php

declare(strict_types=1);

/**
 * Category (and Subcategory) browse (public-web-ux §4): what this kind of
 * business is, how to narrow it, then the businesses.
 *
 * @var \Bulbula\Backend\Domain\Directory\Category      $category
 * @var \Bulbula\Backend\Domain\Directory\Category|null $parent
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
        <li><span aria-current="page"><?= e($category->name) ?></span></li>
    </ol>
</nav>

<section class="wrap section">
    <h1 class="section__title"><?= e($category->name) ?></h1>
    <?php if ($category->nameAm !== null): ?>
        <p class="listing__name-am" lang="am"><?= e($category->nameAm) ?></p>
    <?php endif; ?>

    <?php if ($results->subcategories !== []): ?>
        <ul class="chips" aria-label="Subcategories">
            <?php foreach ($results->subcategories as $facet): ?>
                <li>
                    <a class="chip chip--link" href="<?= e(url('/c/' . ($parent ?? $category)->slug->value . '/' . $facet['category']->slug->value)) ?>">
                        <?= e($facet['category']->name) ?> (<?= e((string) $facet['count']) ?>)
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($results->siblings !== []): ?>
        <ul class="chips" aria-label="Sibling subcategories">
            <?php foreach ($results->siblings as $facet): ?>
                <li>
                    <a class="chip chip--link" href="<?= e(url('/c/' . ($parent ?? $category)->slug->value . '/' . $facet['category']->slug->value)) ?>">
                        <?= e($facet['category']->name) ?> (<?= e((string) $facet['count']) ?>)
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($results->areaFacets !== []): ?>
        <nav class="facets" aria-label="Areas with listings">
            <h2 class="facets__title">In these areas</h2>
            <ul class="chips">
                <?php foreach ($results->areaFacets as $facet): ?>
                    <li>
                        <a class="chip chip--link" href="<?= e(url('/c/' . ($parent !== null ? $parent->slug->value . '/' : '') . $category->slug->value . '/in/' . $facet['area']->slug->value)) ?>">
                            <?= e($facet['area']->name) ?> (<?= e((string) $facet['count']) ?>)
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>

    <?php if ($results->isEmpty()): ?>
        <p class="notice notice--info">
            Nothing is published under <?= e($category->name) ?> yet.
        </p>
        <h2 class="section__title">Nearest alternatives</h2>
        <ul class="links">
            <?php if ($parent !== null): ?>
                <li><a href="<?= e(url('/c/' . $parent->slug->value)) ?>">Everything in <?= e($parent->name) ?></a></li>
            <?php endif; ?>
            <?php foreach ($results->areaFacets as $facet): ?>
                <li><a href="<?= e(url('/a/' . $facet['area']->slug->value)) ?>">Everything in <?= e($facet['area']->name) ?></a></li>
            <?php endforeach; ?>
            <li><a href="<?= e(url('/categories')) ?>">Browse all Categories</a></li>
            <li><a href="<?= e(url('/areas')) ?>">Browse all Areas</a></li>
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
        $base = '/c/' . ($parent !== null ? $parent->slug->value . '/' : '') . $category->slug->value;
        $params = [];
        require __DIR__ . '/../partials/pagination.php';
        ?>
    <?php endif; ?>
</section>
