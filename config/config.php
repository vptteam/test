<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SENDAM BOT ENGINE
|--------------------------------------------------------------------------
|
| Central application configuration.
|
| Location:
|
|     config/config.php
|
| IMPORTANT:
|
| 1. Replace every CHANGE_ME value with your real configuration.
| 2. Never commit this file containing real API secrets to Git.
| 3. Keep Paystack secret keys server-side only.
| 4. Webhook secrets must match the values configured at the provider.
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| APPLICATION
|--------------------------------------------------------------------------
*/

defined('APP_NAME')
    || define(
        'APP_NAME',
        'SENDAM BOT'
    );


defined('APP_ENV')
    || define(
        'APP_ENV',
        'production'
    );


defined('APP_DEBUG')
    || define(
        'APP_DEBUG',
        false
    );


defined('APP_URL')
    || define(
        'APP_URL',
        'https://bot.pingcheckout.com'
    );


defined('APP_TIMEZONE')
    || define(
        'APP_TIMEZONE',
        'Africa/Lagos'
    );


date_default_timezone_set(
    APP_TIMEZONE
);

define(
    'PAYSTACK_DEFAULT_EMAIL',
     'sendamfitness@gmail.com'
);



/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
|
| These values are consumed by Core\Database.
|
*/

defined('DB_HOST')
    || define(
        'DB_HOST',
        'localhost'
    );


defined('DB_PORT')
    || define(
        'DB_PORT',
        '3306'
    );


defined('DB_NAME')
    || define(
        'DB_NAME',
        'u189266834_jolene'
    );


defined('DB_USER')
    || define(
        'DB_USER',
        'u189266834_jolene'
    );


defined('DB_PASS')
    || define(
        'DB_PASS',
        'n1Q=*oiwF4U'
    );


defined('DB_CHARSET')
    || define(
        'DB_CHARSET',
        'utf8mb4'
    );


/*
|--------------------------------------------------------------------------
| LOGGING
|--------------------------------------------------------------------------
*/

defined('LOG_ENABLED')
    || define(
        'LOG_ENABLED',
        true
    );


defined('LOG_LEVEL')
    || define(
        'LOG_LEVEL',
        'debug'
    );


defined('LOG_DIRECTORY')
    || define(
        'LOG_DIRECTORY',
        dirname(__DIR__) . '/storage/logs'
    );

define(
    'UPLOAD_PATH',
    dirname(__DIR__) . '/uploads/'
);


/*
|--------------------------------------------------------------------------
| TELEGRAM
|--------------------------------------------------------------------------
*/

defined('TELEGRAM_BOT_TOKEN')
    || define(
        'TELEGRAM_BOT_TOKEN',
        '8945500663:AAG8XpFB4NNTLxmCiqT66U-pNXhMJh9e2pU'
    );


/*
 * Secret used when registering/verifying the Telegram webhook.
 */
defined('TELEGRAM_WEBHOOK_SECRET')
    || define(
        'TELEGRAM_WEBHOOK_SECRET',
        '763664646'
    );


defined('TELEGRAM_API_URL')
    || define(
        'TELEGRAM_API_URL',
        'https://api.telegram.org'
    );


defined('TELEGRAM_WEBHOOK_URL')
    || define(
        'TELEGRAM_WEBHOOK_URL',
        APP_URL . '/webhook/telegram'
    );

defined('TELEGRAM_CHANNEL_ID') || define(
    'TELEGRAM_CHANNEL_ID',
    '-1004430801113'
);

define(
    'TELEGRAM_BOT_USERNAME',
    'sendambot'
);

/*
|--------------------------------------------------------------------------
| WHATSAPP CLOUD API
|--------------------------------------------------------------------------
*/

defined('WHATSAPP_ACCESS_TOKEN')
    || define(
        'WHATSAPP_ACCESS_TOKEN',
        'CHANGE_ME_WHATSAPP_ACCESS_TOKEN'
    );


defined('WHATSAPP_PHONE_NUMBER_ID')
    || define(
        'WHATSAPP_PHONE_NUMBER_ID',
        'CHANGE_ME_WHATSAPP_PHONE_NUMBER_ID'
    );


defined('WHATSAPP_BUSINESS_ACCOUNT_ID')
    || define(
        'WHATSAPP_BUSINESS_ACCOUNT_ID',
        'CHANGE_ME_WHATSAPP_BUSINESS_ACCOUNT_ID'
    );


defined('WHATSAPP_VERIFY_TOKEN')
    || define(
        'WHATSAPP_VERIFY_TOKEN',
        'CHANGE_ME_WHATSAPP_VERIFY_TOKEN'
    );


defined('WHATSAPP_APP_SECRET')
    || define(
        'WHATSAPP_APP_SECRET',
        'CHANGE_ME_WHATSAPP_APP_SECRET'
    );


defined('WHATSAPP_API_VERSION')
    || define(
        'WHATSAPP_API_VERSION',
        'v23.0'
    );


defined('WHATSAPP_API_URL')
    || define(
        'WHATSAPP_API_URL',
        'https://graph.facebook.com'
    );


defined('WHATSAPP_WEBHOOK_URL')
    || define(
        'WHATSAPP_WEBHOOK_URL',
        APP_URL . '/webhook/whatsapp'
    );

define(
    'WHATSAPP_PROVIDER',
    'twilio'
);


/*
|--------------------------------------------------------------------------
| TWILIO
|--------------------------------------------------------------------------
|
| Used by the Twilio/WhatsApp integration.
|
*/

defined('TWILIO_ACCOUNT_SID')
    || define(
        'TWILIO_ACCOUNT_SID',
        'ACafe386df0db895eabf8b974c8e4a9a5e'
    );


defined('TWILIO_AUTH_TOKEN')
    || define(
        'TWILIO_AUTH_TOKEN',
        'bbc9900bdc638975b1d18f02d56e2388'
    );


defined('TWILIO_WHATSAPP_FROM')
    || define(
        'TWILIO_WHATSAPP_FROM',
        'whatsapp:+14155238886'
    );


defined('TWILIO_WEBHOOK_SECRET')
    || define(
        'TWILIO_WEBHOOK_SECRET',
        'CHANGE_ME_TWILIO_WEBHOOK_SECRET'
    );


/*
|--------------------------------------------------------------------------
| PAYSTACK
|--------------------------------------------------------------------------
|
| Paystack is used for advert payments and escrow payments.
|
| IMPORTANT:
|
| PAYSTACK_SECRET_KEY must NEVER be exposed to browser/client code.
|
*/

defined('PAYSTACK_SECRET_KEY')
    || define(
        'PAYSTACK_SECRET_KEY',
        'sk_live_b581a400ea79460780f8e3be00ac40a6877c4fb8'
    );


defined('PAYSTACK_PUBLIC_KEY')
    || define(
        'PAYSTACK_PUBLIC_KEY',
        'pk_live_0125a656d77ae1d6f7d9b6d1b7e6bb7d84931a3f'
    );


defined('PAYSTACK_BASE_URL')
    || define(
        'PAYSTACK_BASE_URL',
        'https://api.paystack.co'
    );


/*
|--------------------------------------------------------------------------
| PAYSTACK WEBHOOK
|--------------------------------------------------------------------------
|
| Paystack signs webhook payloads with the secret key.
|
| The webhook listeners should validate:
|
|     X-Paystack-Signature
|
| using PAYSTACK_SECRET_KEY.
|
*/

defined('PAYSTACK_WEBHOOK_URL')
    || define(
        'PAYSTACK_WEBHOOK_URL',
        APP_URL . '/payment/paystack/advert/webhook'
    );


defined('PAYSTACK_ESCROW_WEBHOOK_URL')
    || define(
        'PAYSTACK_ESCROW_WEBHOOK_URL',
        APP_URL . '/payment/paystack/escrow/webhook'
    );


/*
|--------------------------------------------------------------------------
| PAYMENT CALLBACK
|--------------------------------------------------------------------------
*/

defined('PAYSTACK_CALLBACK_URL')
    || define(
        'PAYSTACK_CALLBACK_URL',
        APP_URL . '/payment/paystack/callback'
    );


defined('PAYSTACK_ESCROW_CALLBACK_URL')
    || define(
        'PAYSTACK_ESCROW_CALLBACK_URL',
        APP_URL . '/payment/paystack/escrow/callback'
    );


/*
|--------------------------------------------------------------------------
| CURRENCY
|--------------------------------------------------------------------------
*/

defined('DEFAULT_CURRENCY')
    || define(
        'DEFAULT_CURRENCY',
        'NGN'
    );


defined('PAYSTACK_CURRENCY')
    || define(
        'PAYSTACK_CURRENCY',
        'NGN'
    );


/*
|--------------------------------------------------------------------------
| ESCROW
|--------------------------------------------------------------------------
*/

defined('ESCROW_PAYMENT_METHOD')
    || define(
        'ESCROW_PAYMENT_METHOD',
        'paystack'
    );


defined('ESCROW_DEFAULT_DELIVERY_TYPE')
    || define(
        'ESCROW_DEFAULT_DELIVERY_TYPE',
        'digital'
    );


/*
|--------------------------------------------------------------------------
| ESCROW SETTINGS
|--------------------------------------------------------------------------
|
| These are application defaults.
|
| Database settings remain the source of truth where the existing
| EscrowSettings service is used.
|
*/

defined('ESCROW_AUTO_PAYOUT')
    || define(
        'ESCROW_AUTO_PAYOUT',
        false
    );


defined('ESCROW_REQUIRE_ADMIN_APPROVAL')
    || define(
        'ESCROW_REQUIRE_ADMIN_APPROVAL',
        true
    );


/*
|--------------------------------------------------------------------------
| BOT ENGINE
|--------------------------------------------------------------------------
*/

defined('BOT_ENGINE_ENABLED')
    || define(
        'BOT_ENGINE_ENABLED',
        true
    );


defined('BOT_ENGINE_LOG_ENABLED')
    || define(
        'BOT_ENGINE_LOG_ENABLED',
        true
    );


/*
|--------------------------------------------------------------------------
| WEBHOOK LOGGING
|--------------------------------------------------------------------------
*/

defined('WEBHOOK_LOG_PAYLOADS')
    || define(
        'WEBHOOK_LOG_PAYLOADS',
        true
    );


/*
|--------------------------------------------------------------------------
| SECURITY
|--------------------------------------------------------------------------
*/

defined('APP_SECRET')
    || define(
        'APP_SECRET',
        'CHANGE_ME_APPLICATION_SECRET'
    );


defined('WEBHOOK_SECRET')
    || define(
        'WEBHOOK_SECRET',
        'CHANGE_ME_WEBHOOK_SECRET'
    );


/*
|--------------------------------------------------------------------------
| REQUEST SETTINGS
|--------------------------------------------------------------------------
*/

defined('HTTP_TIMEOUT')
    || define(
        'HTTP_TIMEOUT',
        30
    );


defined('HTTP_CONNECT_TIMEOUT')
    || define(
        'HTTP_CONNECT_TIMEOUT',
        10
    );


/*
|--------------------------------------------------------------------------
| STORAGE
|--------------------------------------------------------------------------
*/

defined('STORAGE_PATH')
    || define(
        'STORAGE_PATH',
        dirname(__DIR__) . '/storage'
    );


defined('LOG_PATH')
    || define(
        'LOG_PATH',
        STORAGE_PATH . '/logs'
    );


defined('MEDIA_PATH')
    || define(
        'MEDIA_PATH',
        STORAGE_PATH . '/media'
    );


/*
|--------------------------------------------------------------------------
| URLS
|--------------------------------------------------------------------------
*/

defined('BOT_URL')
    || define(
        'BOT_URL',
        'https://t.me/sendambot'
    );


defined('API_BASE_URL')
    || define(
        'API_BASE_URL',
        APP_URL . '/api'
    );


/*
|--------------------------------------------------------------------------
| ENVIRONMENT HELPERS
|--------------------------------------------------------------------------
*/

defined('IS_PRODUCTION')
    || define(
        'IS_PRODUCTION',
        APP_ENV === 'production'
    );


defined('IS_DEVELOPMENT')
    || define(
        'IS_DEVELOPMENT',
        APP_ENV === 'development'
    );


/*
|--------------------------------------------------------------------------
| CREATE REQUIRED DIRECTORIES
|--------------------------------------------------------------------------
*/

$directories = [
    STORAGE_PATH,
    LOG_PATH,
    MEDIA_PATH,
];


foreach ($directories as $directory) {

    if (
        !is_dir($directory)
    ) {

        @mkdir(
            $directory,
            0755,
            true
        );
    }
}


/*
|--------------------------------------------------------------------------
| CONFIGURATION COMPLETE
|--------------------------------------------------------------------------
*/

if (
    APP_DEBUG
) {

    error_reporting(
        E_ALL
    );

    ini_set(
        'display_errors',
        '1'
    );

} else {

    error_reporting(
        E_ALL
    );

    ini_set(
        'display_errors',
        '0'
    );
}
