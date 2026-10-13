<?php

declare(strict_types=1);

/**
 * Contact — the help route (IAR-11) and the correction channel (C-15).
 *
 * @var string $contactEmail  the address configuration publishes; may be empty
 */
?>
<section class="wrap section">
    <h1 class="section__title">Contact</h1>

    <p class="section__lede">
        Bulbula lists Ethiopian businesses and the facts we can verify about them.
        If something is wrong, missing or out of date, telling us is how it gets fixed.
    </p>

    <?php if ($contactEmail !== ''): ?>
        <p class="section__lede">
            Write to <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a>.
        </p>
    <?php else: ?>
        <p class="notice notice--info">
            The correction form arrives with the reviews and reports feature. Until then
            this page carries no channel, rather than one that would go unanswered.
        </p>
    <?php endif; ?>

    <h2 class="section__title">What helps most</h2>
    <ul class="links">
        <li>A business that should be listed &mdash; its name and where it is.</li>
        <li>A fact on a listing that is wrong &mdash; which listing, and what is wrong.</li>
        <li>A business that has closed &mdash; so its page can say so.</li>
    </ul>
</section>
