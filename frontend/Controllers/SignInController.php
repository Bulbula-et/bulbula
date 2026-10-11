<?php

declare(strict_types=1);

namespace Bulbula\Frontend\Controllers;

use Bulbula\Backend\Core\Config;
use Bulbula\Backend\Core\Cookie;
use Bulbula\Backend\Core\Request;
use Bulbula\Backend\Core\Response;
use Bulbula\Backend\Core\View;
use Bulbula\Backend\Domain\Identity\Email;
use Bulbula\Backend\Domain\Identity\Surface;
use Bulbula\Backend\Exceptions\CsrfTokenMismatchException;
use Bulbula\Backend\Exceptions\OtpVerificationException;
use Bulbula\Backend\Exceptions\RateLimitException;
use Bulbula\Backend\Exceptions\ValidationException;
use Bulbula\Backend\Security\Csrf;
use Bulbula\Backend\Services\Identity\RequestOtpService;
use Bulbula\Backend\Services\Identity\SessionService;
use Bulbula\Backend\Services\Identity\VerifyOtpService;
use InvalidArgumentException;

/**
 * Signing in with an email one-time code (C-31), and signing out.
 *
 * A controller reads the request, calls services, and returns a response. The
 * security rules live in the services; what lives here is the shape of the
 * conversation with a browser:
 *
 * - every state-changing form carries a CSRF token, and the token *is* the
 *   code's context binding (OTP-7) — the code can only be used from the browser
 *   that asked for it;
 * - the answer to "send me a code" never depends on whether the address has an
 *   account (OTP-8, AU-2);
 * - a failure to verify says one thing, and points at the same form (OTP-8);
 * - signing in sets two cookies — the session and a **fresh** CSRF token,
 *   because a pre-authentication value is never promoted (K-6, S-5).
 */
final class SignInController
{
    public function __construct(
        private readonly View $view,
        private readonly Config $config,
        private readonly Csrf $csrf,
        private readonly RequestOtpService $codes,
        private readonly VerifyOtpService $verification,
        private readonly SessionService $sessions,
    ) {
    }

    /** The page, with a fresh CSRF token in a cookie and in the form. */
    public function show(Request $request): Response
    {
        return $this->page($request, $this->csrf->newToken());
    }

    /** Ask for a code. The response is the same whether or not the account exists. */
    public function requestCode(Request $request): Response
    {
        $token = $this->requireToken($request);
        $email = $this->email($request);

        try {
            $this->codes->request(
                $email,
                sessionBinding: $this->csrf->hash($token),
                sourceNetwork: $this->network($request),
            );
        } catch (RateLimitException $limited) {
            return $this->page(
                $request,
                $token,
                notice: 'If that address can sign in, a code is on its way. Please wait a moment before asking again.',
                status: 429,
            );
        }

        return $this->page(
            $request,
            $token,
            notice: 'If that address can sign in, a code is on its way. Enter it below.',
            stage: 'verify',
            email: $email->value,
        );
    }

    /** Exchange a code for a session. */
    public function verify(Request $request): Response
    {
        $token = $this->requireToken($request);
        $email = $this->email($request);
        $code = $request->trimmed('code');

        if ($code === '') {
            return $this->page($request, $token, error: 'Enter the code from the email.', stage: 'verify', email: $email->value);
        }

        try {
            $issued = $this->verification->verify(
                $email,
                $code,
                Surface::Web,
                sessionBinding: $this->csrf->hash($token),
                sourceNetwork: $this->network($request),
                userAgent: $request->header('user-agent'),
            );
        } catch (OtpVerificationException) {
            return $this->page(
                $request,
                $token,
                error: 'That code could not be used. Ask for a new one if you need to.',
                stage: 'verify',
                email: $email->value,
            );
        } catch (RateLimitException) {
            return $this->page(
                $request,
                $token,
                error: 'Too many attempts. Please wait a moment and try again.',
                stage: 'verify',
                email: $email->value,
                status: 429,
            );
        }

        // Two cookies, never one reused: the session, and a CSRF token bound
        // to that session (CS-4, K-6, S-5).
        return Response::redirect('/')
            ->withCookie($this->sessionCookie($issued->token))
            ->withCookie($this->csrfCookie($issued->csrfToken));
    }

    /**
     * Sign out.
     *
     * The form carries a CSRF token and it is checked against **this** session
     * before anything is revoked: a sign-out that any page can trigger is a
     * sign-out an attacker can trigger.
     */
    public function signOut(Request $request): Response
    {
        $token = $request->trimmed(Csrf::FORM_FIELD);
        $sessionToken = $request->cookie($this->sessionCookieName());

        if ($sessionToken !== null) {
            $session = $this->sessions->resolve($sessionToken);

            if ($session !== null && !$this->csrf->matchesSession($token, $session)) {
                throw new CsrfTokenMismatchException();
            }
        }

        if ($sessionToken !== null) {
            $this->sessions->revoke($sessionToken);
        }

        return Response::redirect('/')
            ->withCookie(Cookie::forget($this->sessionCookieName(), '/', $this->secureCookies()))
            ->withCookie(Cookie::forget($this->csrf->cookieName(), '/', $this->secureCookies()));
    }

    /**
     * The CSRF token for this request, or a refusal.
     *
     * Both halves must be present and equal: the cookie the browser sent and
     * the field the form submitted. A missing token is not an empty token —
     * it is a request that did not come from our page.
     */
    private function requireToken(Request $request): string
    {
        $submitted = $request->trimmed(Csrf::FORM_FIELD);
        $cookie = $request->cookie($this->csrf->cookieName());

        if (!$this->csrf->matches($submitted, $cookie)) {
            throw new CsrfTokenMismatchException();
        }

        return $submitted;
    }

    /** @throws ValidationException when the address is not one */
    private function email(Request $request): Email
    {
        try {
            return Email::fromString($request->trimmed('email'));
        } catch (InvalidArgumentException) {
            throw new ValidationException(['email' => 'Enter a valid email address.']);
        }
    }

    /** The caller's network, for the second rate-limit dimension (OTP-6). */
    private function network(Request $request): ?string
    {
        return $request->clientIp;
    }

    private function page(
        Request $request,
        string $csrfToken,
        ?string $notice = null,
        ?string $error = null,
        string $stage = 'request',
        ?string $email = null,
        int $status = 200,
    ): Response {
        $html = $this->view->render('layouts/app', [
            'title'       => 'Sign in — Bulbula',
            'description' => 'Sign in to Bulbula with an email one-time code.',
            'content'     => $this->view->capture('pages/sign-in', [
                'csrfToken' => $csrfToken,
                'notice'    => $notice,
                'error'     => $error,
                'stage'     => $stage,
                'email'     => $email ?? $request->trimmed('email'),
            ]),
        ], $status);

        // The token is (re)issued with every page that carries a form, so the
        // cookie and the field always agree even after a failed attempt.
        return $html->withCookie($this->csrfCookie($csrfToken));
    }

    private function sessionCookie(string $token): Cookie
    {
        return new Cookie(
            name: $this->sessionCookieName(),
            value: $token,
            maxAge: $this->config->int('security.session.absolute_lifetime', 1209600),
            path: '/',
            secure: $this->secureCookies(),
            httpOnly: true,
            sameSite: Cookie::SAME_SITE_LAX,
        );
    }

    private function csrfCookie(string $token): Cookie
    {
        return new Cookie(
            name: $this->csrf->cookieName(),
            value: $token,
            maxAge: $this->config->int('security.session.absolute_lifetime', 1209600),
            path: '/',
            secure: $this->secureCookies(),
            httpOnly: true,
            sameSite: Cookie::SAME_SITE_LAX,
        );
    }

    private function sessionCookieName(): string
    {
        return $this->config->string('security.session.cookie', 'bulbula_session');
    }

    private function secureCookies(): bool
    {
        return $this->config->bool('security.session.secure', true);
    }
}
