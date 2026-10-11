<?php

declare(strict_types=1);

use Bulbula\Backend\Core\Env;

return [
    /*
     * How mail leaves the application.
     *
     *   local    — write messages to `mail.local_path`; refuses to run unless
     *              APP_ENV=local. The only way to complete sign-in while no
     *              provider exists.
     *   provider — the transport selected under D-41, which will live in
     *              Backend\Integrations (TD-07).
     *
     * Anything else means **not deliverable**, and the application says so
     * rather than pretending: the alternative transport available today is a
     * logger, and OTP-10 forbids writing a sign-in code to a log.
     */
    'transport'  => Env::get('MAIL_TRANSPORT', 'log'),
    'from'       => Env::get('MAIL_FROM', 'Bulbula <no-reply@localhost>'),
    'dsn'        => Env::get('MAIL_DSN', ''),
    'local_path' => Env::get('MAIL_LOCAL_PATH', 'storage/tmp/mail'),
];
