<?php

declare(strict_types=1);

/**
 * @var string|null $term  the submitted query, if there was one
 */
?>
<section class="wrap section">
    <h1 class="section__title">Search</h1>

    <?php if ($term !== null): ?>
        <p class="section__lede">
            You searched for &ldquo;<?= e($term) ?>&rdquo;.
        </p>
    <?php endif; ?>

    <p class="notice notice--info">
        The directory is being prepared. Search opens as soon as the first listings are
        published &mdash; Bulbula does not show an empty shelf and call it a result.
    </p>

    <p><a href="<?= e(url('/')) ?>">Back to the homepage</a></p>
</section>
