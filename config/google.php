<?php

declare(strict_types=1);

use Bulbula\Backend\Core\Env;

/*
 * Google sign-in (C-30, GA-1…GA-6).
 *
 * Only one value is genuinely public here: the client id, which travels in the
 * page by design. There is no secret, because this flow verifies Google's
 * signed assertion against Google's published keys rather than exchanging a
 * code — see `deployment.md`, which lists a secret for Google: that secret
 * belongs to the code-exchange flow, which this build does not use.
 *
 * Several client ids may be listed (web, Android, iOS): a token is accepted
 * only if its audience is one of them, so "any Google token" is never enough.
 */
return [
    'client_ids'    => Env::get('GOOGLE_CLIENT_IDS', ''),
    'issuers'       => 'https://accounts.google.com,accounts.google.com',
    'jwks_url'      => Env::get('GOOGLE_JWKS_URL', 'https://www.googleapis.com/oauth2/v3/certs'),
    'keys_ttl'      => Env::int('GOOGLE_KEYS_TTL', 3600),
    'keys_cache'    => Env::get('GOOGLE_KEYS_CACHE', 'storage/tmp/keys/google.json'),
    'max_token_age' => Env::int('GOOGLE_MAX_TOKEN_AGE', 600),
    'clock_leeway'  => Env::int('GOOGLE_CLOCK_LEEWAY', 60),
];
