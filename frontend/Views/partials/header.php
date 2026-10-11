<?php

declare(strict_types=1);

?>
<header class="site-header">
    <div class="wrap site-header__inner">
        <a class="brand" href="<?= e(url('/')) ?>">
            <span class="brand__mark" aria-hidden="true">B</span>
            <span class="brand__name">Bulbula</span>
        </a>

        <nav class="site-nav" aria-label="Main">
            <a href="<?= e(url('/search')) ?>">Search</a>
            <a href="<?= e(url('/contact')) ?>">Contact</a>
        </nav>
    </div>
</header>
