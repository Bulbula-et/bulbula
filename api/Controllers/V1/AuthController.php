<?php

declare(strict_types=1);

namespace Bulbula\Api\Controllers\V1;

use Bulbula\Backend\Core\Request;
use Bulbula\Backend\Core\Response;
use Bulbula\Backend\Domain\Identity\Email;
use Bulbula\Backend\Domain\Identity\Surface;
use Bulbula\Backend\Exceptions\AuthenticationRequiredException;
use Bulbula\Backend\Exceptions\ValidationException;
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
 * `POST /auth/google`, `/auth/telegram/context` and `GET /auth/session` are not
 * built: the first needs the Google adapter, the second is a surface signal
 * whose relationship to identity is open (D-33), and the third reports the
 * capabilities of the permission model, which is the next slice.
 */
final class AuthController
{
    public function __construct(
        private readonly RequestOtpService $codes,
        private readonly VerifyOtpService $verification,
        private readonly SessionService $sessions,
    ) {
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
