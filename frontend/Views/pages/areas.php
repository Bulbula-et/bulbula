<?php

declare(strict_types=1);

/**
 * Areas index (public-web-ux §5): the geography Bulbula covers, grouped by
 * Sub-city with real counts (C-05, GEO-6).
 *
 * @var \Bulbula\Backend\Services\Search\Catalogue $catalogue
 */
?>
<nav class="breadcrumb wrap" aria-label="Breadcrumb">
    <ol class="breadcrumb__list">
        <li><a href="<?= e(url('/')) ?>">Home</a></li>
        <li><span aria-current="page">Areas</span></li>
    </ol>
</nav>

<section class="wrap section">
    <h1 class="section__title">Areas</h1>

    <?php if ($catalogue->areas === []): ?>
        <p class="notice notice--info">
            Nothing is published yet. Bulbula does not invent areas to look busy.
        </p>
        <p class="section__lede">
            Something missing? <a href="<?= e(url('/contact')) ?>">Tell us what&rsquo;s missing</a>.
        </p>
    <?php else: ?>
        <?php foreach ($catalogue->areas as $group): ?>
            <section class="facets" aria-labelledby="sub-city-<?= e((string) $group['subCity']->id) ?>">
                <h2 class="facets__title" id="sub-city-<?= e((string) $group['subCity']->id) ?>"><?= e($group['subCity']->name) ?></h2>
                <ul class="tiles">
                    <?php foreach ($group['areas'] as $tile): ?>
                        <li class="tile">
                            <a class="tile__link" href="<?= e(url('/a/' . $tile['area']->slug->value)) ?>">
                                <span class="tile__name"><?= e($tile['area']->name) ?></span>
                                <span class="tile__count"><?= e((string) $tile['count']) ?>&nbsp;<?= $tile['count'] === 1 ? 'listing' : 'listings' ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
