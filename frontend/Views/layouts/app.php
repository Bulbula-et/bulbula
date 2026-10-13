<?php

declare(strict_types=1);

/**
 * The public page layout.
 *
 * @var string      $title
 * @var string|null $description
 * @var string|null $robots    e.g. "noindex, follow" — pages that must not
 *                             enter the search index (SEO-8); absent otherwise
 * @var string      $content  rendered page markup, already escaped by its template
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Bulbula') ?></title>
    <?php if (($description ?? '') !== ''): ?>
        <meta name="description" content="<?= e($description) ?>">
    <?php endif; ?>
    <?php if (($robots ?? '') !== ''): ?>
        <meta name="robots" content="<?= e($robots) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<?php require __DIR__ . '/../partials/header.php'; ?>

<a class="skip-link" href="#main">Skip to content</a>

<main id="main">
    <?php
    /*
     * $content is markup already rendered by a page template and captured by
     * the controller. It is the one value in this file that is printed without
     * e() — every other value, on every page, is escaped.
     */
    echo $content;
?>
</main>

<?php require __DIR__ . '/../partials/footer.php'; ?>
</body>
</html>
