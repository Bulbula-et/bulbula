<?php

declare(strict_types=1);

/**
 * Sign in with an email one-time code.
 *
 * Two stages on one page: ask for a code, then enter it. No JavaScript — a
 * form and a redirect do the whole job, which means the flow works with
 * scripting off, on a slow connection, and without a build step.
 *
 * @var string      $csrfToken
 * @var string|null $notice      something to say that is not an error
 * @var string|null $error       what went wrong, in plain words
 * @var string      $stage       `request` or `verify`
 * @var string      $email       what the person typed, so they do not retype it
 * @var string|null $googleClientId  the configured client id, or empty when Google is off
 * @var string|null $googleLoginUri  the absolute address Google posts the credential back to
 */
?>
<section class="wrap section sign-in">
    <h1 class="section__title">Sign in</h1>

    <p class="sign-in__lede">
        Bulbula has no passwords. Enter your email address and we will send a
        one-time code — that is the whole of it.
    </p>

    <?php if (($notice ?? null) !== null): ?>
        <p class="notice" role="status"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if (($error ?? null) !== null): ?>
        <p class="notice notice--error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if (($stage ?? 'request') === 'request'): ?>
        <form class="form" method="post" action="<?= e(url('/sign-in/code')) ?>">
            <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">

            <label class="form__label" for="email">Email address</label>
            <input
                class="form__input"
                id="email"
                name="email"
                type="email"
                inputmode="email"
                autocomplete="email"
                required
                value="<?= e($email ?? '') ?>"
            >

            <button class="button" type="submit">Send me a code</button>
        </form>
    <?php else: ?>
        <form class="form" method="post" action="<?= e(url('/sign-in/verify')) ?>">
            <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="email" value="<?= e($email) ?>">

            <label class="form__label" for="code">The code from the email</label>
            <input
                class="form__input form__input--code"
                id="code"
                name="code"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                pattern="[0-9]*"
                required
            >

            <button class="button" type="submit">Sign in</button>
        </form>

        <form class="form form--inline" method="post" action="<?= e(url('/sign-in/code')) ?>">
            <input type="hidden" name="csrf" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="email" value="<?= e($email) ?>">
            <button class="button button--quiet" type="submit">Send a new code</button>
        </form>
    <?php endif; ?>

    <p class="sign-in__note">
        By signing in you agree to our <a href="<?= e(url('/terms')) ?>">terms</a>
        and <a href="<?= e(url('/privacy')) ?>">privacy notice</a>.
    </p>
</section>

<?php if (($stage ?? 'request') === 'request' && ($googleClientId ?? '') !== ''): ?>
    <section class="wrap section sign-in">
        <h2 class="section__title">Or use Google</h2>

        <p class="sign-in__lede">
            Signing in with Google uses Google's own button. Your email address
            is still the account — Google only tells us you hold it.
        </p>

        <div
            class="g_id_signin"
            data-type="standard"
            data-theme="outline"
            data-size="large"
            data-text="signin_with"
            data-shape="rectangular"
            data-logo_alignment="left"
            data-ux_mode="redirect"
            data-login_uri="<?= e($googleLoginUri ?? url('/sign-in/google')) ?>"
            data-client_id="<?= e($googleClientId) ?>"
        ></div>

        <script src="https://accounts.google.com/gsi/client" async defer></script>

        <p class="sign-in__note">
            The Google button needs JavaScript from Google. The email code never does.
        </p>
    </section>
<?php endif; ?>
