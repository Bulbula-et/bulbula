<?php

declare(strict_types=1);

use Bulbula\Backend\Core\Env;

return [
    // No email provider has been selected. The log transport writes the
    // message it would have sent, so nothing is lost and nothing is sent.
    'transport' => Env::get('MAIL_TRANSPORT', 'log'),
    'from'      => Env::get('MAIL_FROM', 'Bulbula <no-reply@localhost>'),
    'dsn'       => Env::get('MAIL_DSN', ''),
];
