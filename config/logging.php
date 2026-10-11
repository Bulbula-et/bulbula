<?php

declare(strict_types=1);

use Bulbula\Backend\Core\Env;

return [
    'path'  => Env::get('LOG_PATH', 'storage/logs/app.log'),
    'level' => Env::get('LOG_LEVEL', 'warning'),
    'json'  => Env::bool('LOG_JSON', false),
];
