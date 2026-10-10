<?php

declare(strict_types=1);

?>
<section class="hero">
    <div class="wrap hero__inner">
        <h1 class="hero__title">Find a business in Addis Ababa you can trust.</h1>
        <p class="hero__lede">
            Every listing on Bulbula is checked before it is published, and the facts on
            the page are the ones we could verify. Reviews are written by people, moderated
            by people, and never for sale.
        </p>

        <form class="search-form" method="get" action="<?= e(url('/search')) ?>" role="search">
            <label class="visually-hidden" for="q">Search businesses</label>
            <input
                id="q"
                name="q"
                type="search"
                class="search-form__input"
                placeholder="Business, category or area"
                autocomplete="off"
                maxlength="120"
            >
            <button type="submit" class="search-form__button">Search</button>
        </form>
    </div>
</section>

<section class="wrap values" aria-label="How Bulbula works">
    <article class="value">
        <h2 class="value__title">Verified before published</h2>
        <p class="value__body">
            A listing appears only once a person has checked it against a source and
            recorded what was checked.
        </p>
    </article>
    <article class="value">
        <h2 class="value__title">Honest ranking</h2>
        <p class="value__body">
            Paid placement is labelled and kept out of the results. Your search is answered
            by the same ranking whether or not anyone is advertising.
        </p>
    </article>
    <article class="value">
        <h2 class="value__title">No accounts to browse</h2>
        <p class="value__body">
            Reading is open to everyone. An account is needed only to write a review or
            save a place, and it holds no password.
        </p>
    </article>
</section>
