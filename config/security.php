<?php

declare(strict_types=1);

use Bulbula\Backend\Core\Env;

return [
    'session' => [
        'cookie'   => Env::get('SESSION_COOKIE', 'bulbula_session'),
        'lifetime' => Env::int('SESSION_LIFETIME', 1200),
        'samesite' => 'Lax',
        // Cookies are Secure everywhere except local development over http.
        'secure'   => Env::get('APP_ENV', 'production') !== 'local',
    ],

    'csrf' => [
        'enabled' => Env::bool('CSRF_ENABLED', true),
    ],

    // Requests allowed per window, per source. These are configuration, never
    // constants, and the numbers are never disclosed in a response.
    'rate_limits' => [
        'otp_request' => ['limit' => 5,  'window' => 900],
        'otp_verify'  => ['limit' => 10, 'window' => 900],
        'review'      => ['limit' => 10, 'window' => 3600],
        'report'      => ['limit' => 20, 'window' => 3600],
        'search'      => ['limit' => 120, 'window' => 60],
    ],
];
