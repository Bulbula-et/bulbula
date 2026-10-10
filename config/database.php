<?php

declare(strict_types=1);

use Bulbula\Backend\Core\Env;

return [
    // mysql (MariaDB) in the application, sqlite in tests.
    'driver'      => Env::get('DB_DRIVER', 'mysql'),
    'host'        => Env::get('DB_HOST', '127.0.0.1'),
    'port'        => Env::int('DB_PORT', 3306),
    'database'    => Env::get('DB_DATABASE', ''),
    'username'    => Env::get('DB_USERNAME', ''),
    'password'    => Env::get('DB_PASSWORD', ''),
    'charset'     => Env::get('DB_CHARSET', 'utf8mb4'),
    'sqlite_path' => Env::get('DB_SQLITE_PATH', ':memory:'),
];
