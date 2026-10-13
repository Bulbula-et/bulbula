<?php

declare(strict_types=1);

/**
 * Search results — or the designed zero-result destination (public-web-ux
 * §2, user-flows §A7).
 *
 * @var \Bulbula\Backend\Services\Search\SearchRequest $request
 * @var \Bulbula\Backend\Services\Search\SearchResults $results
 * @var \Bulbula\Backend\Services\Search\Catalogue     $catalogue
 */
$filters = [];

if ($request->category !== null) {
    $filters['category'] = $request->category->slug->value;
}

if ($request->area !== null) {
    $filters['area'] = $request->area->slug->value;
}

if ($request->openNow) {
    $filters['open_now'] = '1';
}

if ($request->minRating !== null) {
    $filters['min_rating'] = (string) $request->minRating;
}

if ($request->verifiedOnly) {
    $filters['verified'] = '1';
}

if ($request->sort !== 'relevance') {
    $filters['sort'] = $request->sort;
}

$params = array_merge(['q' => $request->query], $filters);
$remove = static function (string $key) use ($request, $filters): string {
    $kept = $filters;
    unset($kept[$key]);

    return url('/search?' . http_build_query(array_merge(['q' => $request->query], $kept)));
};
?>
<section class="wrap section">
    <h1 class="section__title">Search</h1>

    <form class="search-form search-form--page" method="get" action="<?= e(url('/search')) ?>" role="search">
        <label class="visually-hidden" for="q">Search businesses</label>
        <input
            id="q"
            name="q"
            type="search"
            class="search-form__input"
            value="<?= e($request->query) ?>"
            placeholder="Business, category or area"
            autocomplete="off"
            maxlength="120"
        >
        <button type="submit" class="search-form__button">Search</button>
    </form>

    <?php if ($results->rejected): ?>
        <p class="notice notice--warning">
            That search was too long to run. Try a shorter phrase.
        </p>
    <?php elseif ($results->isEmpty()): ?>
        <?php if ($request->query === '' && $filters === []): ?>
            <p class="section__lede">
                Type a business name, a category or an area &mdash; or start from
                <a href="<?= e(url('/categories')) ?>">Categories</a> or
                <a href="<?= e(url('/areas')) ?>">Areas</a>.
            </p>
        <?php else: ?>
            <h2 class="section__title">No results for &ldquo;<?= e($request->query) ?>&rdquo;</h2>

            <?php if ($results->zero !== null && $results->zero->droppedResults !== null): ?>
                <p class="notice notice--info">
                    We removed the filter: <strong><?= e((string) $results->zero->droppedLabel) ?></strong>.
                </p>

                <h3 class="section__title">
                    Broader matches
                    <?php if ($results->zero->droppedResults->isBroader()): ?>
                        <span class="chip chip--label">broader</span>
                    <?php endif; ?>
                </h3>
                <ul class="cards">
                    <?php foreach ($results->zero->droppedResults->items as $item) {
                        require __DIR__ . '/../partials/business_card.php';
                    } ?>
                </ul>
            <?php else: ?>
                <p class="section__lede">
                    Nothing matched &mdash; and Bulbula does not show unrelated businesses
                    as if they did.
                </p>
            <?php endif; ?>

            <?php if ($results->zero !== null && $results->zero->alternatives !== []): ?>
                <h3 class="section__title">Nearest alternatives</h3>
                <ul class="links">
                    <?php foreach ($results->zero->alternatives as $alternative): ?>
                        <li><a href="<?= e(url($alternative['url'])) ?>"><?= e($alternative['label']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p class="section__lede">
                Something missing? <a href="<?= e(url('/contact')) ?>">Tell us what&rsquo;s missing</a>
                &mdash; no account needed.
            </p>
        <?php endif; ?>
    <?php else: ?>
        <div class="results-head">
            <p class="results-head__count" role="status">
                <?= e((string) $results->total) ?>&nbsp;<?= $results->total === 1 ? 'result' : 'results' ?>
                <?php if ($results->isBroader()): ?>
                    <span class="chip chip--label">showing broader matches</span>
                <?php endif; ?>
            </p>
            <p class="results-head__sort">
                <a href="<?= e(url('/search?' . http_build_query(array_merge($params, ['sort' => $request->sort === 'name' ? 'relevance' : 'name'])))) ?>">
                    Sort: <?= e($request->sort === 'name' ? 'Name' : 'Relevance') ?>
                </a>
            </p>
        </div>

        <?php if ($filters !== []): ?>
            <ul class="chips" aria-label="Active filters">
                <?php foreach ($filters as $key => $value): ?>
                    <li>
                        <span class="chip">
                            <?= e(match ($key) {
                                'category'  => 'Category: ' . ($request->category->name ?? $value),
                                'area'      => 'Area: ' . ($request->area->name ?? $value),
                                'open_now'  => 'Open now',
                                'min_rating' => 'Rating ' . $value . '+',
                                'verified'  => 'Verified only',
                                'sort'      => 'Sort',
                                default     => (string) $value,
                            }) ?>
                            <a class="chip__remove" href="<?= e($remove($key)) ?>" aria-label="Remove this filter">&times;</a>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <ul class="cards">
            <?php foreach ($results->items as $item) {
                require __DIR__ . '/../partials/business_card.php';
            } ?>
        </ul>

        <?php
        $total = $results->total;
$page = $results->page;
$pageSize = $results->pageSize;
$base = '/search';
require __DIR__ . '/../partials/pagination.php';
?>

        <p class="section__lede">
            Not what you meant? Browse
            <a href="<?= e(url('/categories')) ?>">Categories</a> or
            <a href="<?= e(url('/areas')) ?>">Areas</a> instead.
        </p>
    <?php endif; ?>
</section>
