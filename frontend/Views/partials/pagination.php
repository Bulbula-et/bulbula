<?php

declare(strict_types=1);

/**
 * Pagination (component-spec §27): real links with real URLs, honest totals,
 * the control absent entirely on a single page. No infinite scroll (IAR-30).
 *
 * @var int                  $total
 * @var int                  $page    1-based, already clamped
 * @var int                  $pageSize
 * @var string               $base    the path these pages belong to
 * @var array<string, mixed> $params  the query parameters to carry, without `page`
 */
$lastPage = max(1, (int) ceil($total / max(1, $pageSize)));

if ($lastPage <= 1) {
    return;
}

$link = static function (int $target) use ($base, $params): string {
    return url($base . '?' . http_build_query(array_merge($params, ['page' => $target])));
};
?>
<nav class="pagination" aria-label="Result pages">
    <p class="pagination__position">Page <?= e((string) $page) ?> of <?= e((string) $lastPage) ?></p>
    <p class="pagination__links">
        <?php if ($page > 1): ?>
            <a href="<?= e($link($page - 1)) ?>" rel="prev">Previous</a>
        <?php endif; ?>
        <?php if ($page < $lastPage): ?>
            <a href="<?= e($link($page + 1)) ?>" rel="next">Next</a>
        <?php endif; ?>
    </p>
</nav>
