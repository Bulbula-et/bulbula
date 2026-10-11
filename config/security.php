<?php

declare(strict_types=1);

use Bulbula\Backend\Core\Env;

return [
    'session' => [
        'cookie'   => Env::get('SESSION_COOKIE', 'bulbula_session'),
        // Inactivity window: a session untouched for this long is over.
        'lifetime' => Env::int('SESSION_LIFETIME', 1200),
        // Absolute cap, however active the session is. Both exist (S-6); the
        // values are **TRD OT-01 — Open — technical decision**, hence
        // configuration rather than a constant.
        'absolute_lifetime' => Env::int('SESSION_ABSOLUTE_LIFETIME', 1209600),
        'samesite' => 'Lax',
        // Cookies are Secure everywhere except local development over http.
        'secure'   => Env::get('APP_ENV', 'production') !== 'local',
    ],

    /*
     * Networks whose forwarded client address may be believed (ClientAddress).
     *
     * Empty means none: the peer address is the client address, which is correct
     * when nothing sits in front of the application. A proxy in front — the
     * deployment model this project assumes, and what the development host runs
     * — makes every visitor share one peer address, and the per-network limits
     * (OTP-6, AX-1) and the audit record would then describe the proxy instead of
     * the visitor. Behind Cloudflare, list Cloudflare's own ranges
     * (https://www.cloudflare.com/ips-v4 and /ips-v6); nothing else should be
     * listed, and the origin should not be reachable except through it.
     */
    'trusted_proxies' => Env::get('TRUSTED_PROXIES', ''),

    'csrf' => [
        'enabled' => Env::bool('CSRF_ENABLED', true),
        'cookie'  => Env::get('CSRF_COOKIE', 'bulbula_csrf'),
    ],

    /*
     * Email one-time code policy.
     *
     * The *mechanisms* are fixed by `docs/30-technical/auth-identity.md` §4.2
     * (OTP-1 … OTP-10). These numbers are **TRD OT-02 — Open — technical
     * decision**, due to be confirmed in the security phase; they are here, and
     * not in code, so that confirming them is a configuration change
     * (TRD TR-196). The defaults below are placeholders that sit inside the
     * cited standard's bounds — R-25 caps out-of-band completion at ten
     * minutes — and no response ever discloses them.
     */
    'otp' => [
        'length'          => Env::int('OTP_LENGTH', 6),
        'ttl'             => Env::int('OTP_TTL', 600),
        'max_attempts'    => Env::int('OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown' => Env::int('OTP_RESEND_COOLDOWN', 60),
    ],

    // Requests allowed per window, per source. These are configuration, never
    // constants, and the numbers are never disclosed in a response.
    //
    // `otp_request` is applied twice — once keyed by the address, once by the
    // source network — because OTP-6 limits both. One bucket therefore means
    // one ceiling for both dimensions; a shared network (carrier NAT, an
    // office) reaching the ceiling would need a second bucket here rather than
    // a code change. That trade-off is recorded for OT-02.
    'rate_limits' => [
        'otp_request' => ['limit' => 5,  'window' => 900],
        'otp_verify'  => ['limit' => 10, 'window' => 900],
        'google_signin' => ['limit' => (int) Env::get('RATE_LIMIT_GOOGLE_SIGNIN', '20'), 'window' => 900],
        'review'      => ['limit' => 10, 'window' => 3600],
        'report'      => ['limit' => 20, 'window' => 3600],
        'search'      => ['limit' => 120, 'window' => 60],
    ],
];
