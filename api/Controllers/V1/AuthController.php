<?php

declare(strict_types=1);

namespace Bulbula\Api\Controllers\V1;

use Bulbula\Backend\Core\Request;
use Bulbula\Backend\Core\Response;
use Bulbula\Backend\Domain\Identity\Email;
use Bulbula\Backend\Domain\Identity\Surface;
use Bulbula\Backend\Exceptions\AuthenticationRequiredException;
use Bulbula\Backend\Exceptions\ValidationException;
use Bulbula\Backend\Services\Identity\GoogleSignInService;
use Bulbula\Backend\Services\Identity\RequestOtpService;
use Bulbula\Backend\Services\Identity\SessionService;
use Bulbula\Backend\Services\Identity\VerifyOtpService;
use InvalidArgumentException;

/**
 * Machine sign-in (`api-spec-v1.0.md` §3.1) — C-31, TD-02's second transport.
 *
 * Two differences from the web flow, and both are deliberate:
 *
 * - **No CSRF.** A bearer-authenticated request is not cookie-authenticated
 *   (CS-3), and these endpoints are for clients that hold a token rather than
 *   a browsing cookie. The token is only ever accepted in the `Authorization`
 *   header, never from a cookie, so nothing here can be driven by a page.
 * - **The token is the response.** A client keeps it and sends it back; the
 *   server keeps only its hash (S-3).
 *
 * `/auth/telegram/context` and `GET /auth/session` are not built: the first is
 * a surface signal whose relationship to identity is open (D-33), and the
 * second reports the capabilities of the permission model, which is the next
 * slice.
 */
final class AuthController
{
    public function __construct(
        private readonly RequestOtpService $codes,
        private readonly VerifyOtpService $verification,
        private readonly SessionService $sessions,
        private readonly GoogleSignInService $google,
    ) {
    }

    /**
     * Exchange a Google credential for a session (C-30, GA-1…GA-6).
     *
     * Two answers are possible, and both are honest about what happened:
     *
     * - **201** — the credential verified and a session token is returned.
     * - **202** — the credential verified, but its address already belongs to a
     *   Customer. The identity is not attached until that mailbox proves
     *   itself, so a code has been sent and the client continues at
     *   `/auth/otp/verify` (LK-6, D-13 closed 2026-10-11). 202 rather than 200
     *   because the request is accepted, not finished.
     *
     * A credential that fails verification is a 401 with one message (GA-2).
     */
    public function google(Request $request): Response
    {
        $credential = $request->trimmed('credential');

        if ($credential === '') {
            throw new ValidationException(['credential' => 'A Google credential is required.']);
        }

        $result = $this->google->signIn(
            $credential,
            Surface::Api,
            sourceNetwork: $request->clientIp,
            userAgent: $request->header('user-agent'),
        );

        if ($result->needsConfirmation()) {
            return Response::json([
                'status'  => 'confirm_required',
                'email'   => $this->mask($result->confirmationAddress()->value),
                'message' => 'Enter the code we sent to that address to connect Google to the account it belongs to.',
            ], 202)->withHeader('Cache-Control', 'no-store');
        }

        $issued = $result->issuedSession();

        return Response::json([
            'token'      => $issued->token,
            'expires_at' => $issued->session->expiresAt->format(DATE_ATOM),
            'surface'    => $issued->session->surface->value,
        ], 201)->withHeader('Cache-Control', 'no-store');
    }

    /** `sara@example.com` → `s***@example.com`: enough to recognise, not enough to harvest. */
    private function mask(string $address): string
    {
        $at = strpos($address, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        return substr($address, 0, 1) . '***' . substr($address, $at);
    }

    /**
     * AU-2: the same answer whether or not the address has an account.
     *
     * Even the rate-limit refusal is phrased in terms of the request, not the
     * account — it says the caller has asked too often, which is something the
     * caller already knows.
     */
    public function requestCode(Request $request): Response
    {
        $this->codes->request(
            $this->email($request),
            sourceNetwork: $request->clientIp,
        );

        return Response::json(['status' => 'sent'], 202)
            ->withHeader('Cache-Control', 'no-store');
    }

    /** Exchange a code for a session token (AU-3). */
    public function verify(Request $request): Response
    {
        $issued = $this->verification->verify(
            $this->email($request),
            $request->trimmed('code'),
            Surface::Api,
            sourceNetwork: $request->clientIp,
            userAgent: $request->header('user-agent'),
        );

        return Response::json([
            'token'      => $issued->token,
            'expires_at' => $issued->session->expiresAt->format(DATE_ATOM),
            'surface'    => $issued->session->surface->value,
        ], 201)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Revoke the session this token addresses (C-32).
     *
     * A missing or unknown token is refused rather than ignored: "you were not
     * signed in" and "you have been signed out" are different answers, and a
     * client that cannot tell them apart cannot tell whether its token was
     * already dead (E-5).
     */
    public function logout(Request $request): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw new AuthenticationRequiredException();
        }

        $session = $this->sessions->resolve($token);

        if ($session === null) {
            throw new AuthenticationRequiredException();
        }

        $this->sessions->revoke($token);

        return Response::noContent()->withHeader('Cache-Control', 'no-store');
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
}
