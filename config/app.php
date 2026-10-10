<?php

declare(strict_types=1);

use Bulbula\Backend\Core\Env;

$environment = Env::get('APP_ENV', 'production');

return [
    'name'     => Env::get('APP_NAME', 'Bulbula'),
    'env'      => $environment,
    'url'      => rtrim(Env::get('APP_URL', 'http://localhost'), '/'),
    'timezone' => Env::get('APP_TIMEZONE', 'Africa/Addis_Ababa'),

    // Debug can never be switched on in production, whatever .env says.
    'debug'    => $environment === 'production' ? false : Env::bool('APP_DEBUG', false),
];
