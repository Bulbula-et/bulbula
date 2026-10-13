<?php
declare(strict_types=1);

use Bulbula\Backend\Core\Env;

$environment = Env::get('APP_ENV', 'production');

return [
    'name'     => Env::get('APP_NAME', 'Bulbula'),
    'env'      => $environment,
    'url'      => rtrim(Env::get('APP_URL', 'http://localhost'), '/'),
    'timezone' => Env::get('APP_TIMEZONE', 'Africa/Addis_Ababa'),

    // Retention periods for personal data (days), configured per data class.
    // Set to null or 0 to keep data indefinitely; subject to legal obligations.
    // These values can be changed without code or schema changes — only config.
    'retention' => [
        'account'           => (int) Env::get('RETENTION_ACCOUNT_DAYS', 0),     // 0 = indefinitely
        'review'            => (int) Env::get('RETENTION_REVIEW_DAYS', 365 * 3), // 3 years
        'business'          => (int) Env::get('RETENTION_BUSINESS_DAYS', 0),     // 0 = indefinitely
        'category'          => (int) Env::get('RETENTION_CATEGORY_DAYS', 0),     // 0 = indefinitely
        'area'              => (int) Env::get('RETENTION_AREA_DAYS', 0),        // 0 = indefinitely
        'search_query'      => (int) Env::get('RETENTION_SEARCH_QUERY_DAYS', 30), // 30 days
        'otp_code'          => (int) Env::get('RETENTION_OTP_CODE_DAYS', 7),     // 7 days
        'csrf_token'        => (int) Env::get('RETENTION_CSRF_TOKEN_DAYS', 30),  // 30 days
    ],

    // The address the contact page publishes (IAR-11, C-15). Empty until the
    // team sets one — the page then says so honestly rather than inventing a
    // channel.
    'contact_email' => Env::get('CONTACT_EMAIL', ''),

    // Debug can never be switched on in production, whatever .env says.
    'debug'    => $environment === 'production' ? false : Env::bool('APP_DEBUG', false),

    // Email transport configuration.
    // Currently supports: 'brevo' (SMTP/ API), 'log' (development only), 'ses' (AWS SES).
    // Credentials must be set in .env.bulbulaet and never committed to source.
    'mail' => [
        'transport' => Env::get('MAIL_TRANSPORT', 'brevo'),
        'brevo' => [
            'api_key' => Env::get('BREVO_API_KEY', ''),
            'sender_name' => Env::get('BREVO_SENDER_NAME', 'Bulbula'),
            'sender_email' => Env::get('BREVO_SENDER_EMAIL', ''),
        ],
        'smtp' => [
            'host' => Env::get('SMTP_HOST', ''),
            'port' => (int) Env::get('SMTP_PORT', 587),
            'username' => Env::get('SMTP_USERNAME', ''),
            'password' => Env::get('SMTP_PASSWORD', ''),
            'encryption' => Env::get('SMTP_ENCRYPTION', 'tls'),
        ],
        'log' => [
            'path' => Env::get('MAIL_LOG_PATH', 'storage/tmp/mail'),
        ],
    ],
];