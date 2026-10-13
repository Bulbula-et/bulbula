<?php

declare(strict_types=1);

/**
 * Categories index (public-web-ux §3): what kinds of business exist, each
 * with its real count. Only destinations with published Listings appear
 * (C-04).
 *
 * @var \Bulbula\Backend\Services\Search\Catalogue $catalogue
 */
?>
<nav class="breadcrumb wrap" aria-label="Breadcrumb">
    <ol class="breadcrumb__list">
        <li><a href="<?= e(url('/')) ?>">Home</a></li>
        <li><span aria-current="page">Categories</span></li>
    </ol>
</nav>

<section class="wrap section">
    <h1 class="section__title">Categories</h1>

    <?php if ($catalogue->categories === []): ?>
        <p class="notice notice--info">
            Nothing is published yet. Bulbula does not invent categories to look busy.
        </p>
        <p class="section__lede">
            Something missing? <a href="<?= e(url('/contact')) ?>">Tell us what&rsquo;s missing</a>.
        </p>
    <?php else: ?>
        <ul class="tiles">
            <?php foreach ($catalogue->categories as $tile): ?>
                <li class="tile">
                    <a class="tile__link" href="<?= e(url('/c/' . $tile['category']->slug->value)) ?>">
                        <span class="tile__name"><?= e($tile['category']->name) ?></span>
                        <span class="tile__count"><?= e((string) $tile['count']) ?>&nbsp;<?= $tile['count'] === 1 ? 'listing' : 'listings' ?></span>
                    </a>
                    <?php if ($tile['children'] !== []): ?>
                        <ul class="tile__children">
                            <?php foreach ($tile['children'] as $child): ?>
                                <li>
                                    <a href="<?= e(url('/c/' . $tile['category']->slug->value . '/' . $child['category']->slug->value)) ?>">
                                        <?= e($child['category']->name) ?>
                                        (<?= e((string) $child['count']) ?>)
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
