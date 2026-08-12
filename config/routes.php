<?php

declare(strict_types=1);

use Listeners\Health\HealthListener;

use Listeners\Telegram\TelegramListener;
use Listeners\WhatsApp\WhatsAppListener;

use Listeners\Sms\SmsListener;
use Listeners\Sms\SmsWebhookListener;

use Listeners\Ussd\UssdListener;

use Listeners\Web\WebListener;

use Listeners\Payments\PaystackListener;
use Listeners\Payments\PaystackWebhookListener;
use Listeners\Payments\EscrowPaystackWebhookListener;

use Listeners\Admin\AdminEscrowListener;

use Controllers\EscrowApiController;


/*
|--------------------------------------------------------------------------
| SENDAM / PINGCHECKOUT BOT ENGINE ROUTES
|--------------------------------------------------------------------------
|
| Central HTTP route registry.
|
| Supported transports:
|
| - Health
| - Telegram
| - WhatsApp
| - SMS
| - USSD
| - Universal Web
| - Paystack
| - Escrow
| - Admin
|
|--------------------------------------------------------------------------
*/


return [

    /*
    |--------------------------------------------------------------------------
    | Health Check
    |--------------------------------------------------------------------------
    */

    '/' =>
        HealthListener::class,


    /*
    |--------------------------------------------------------------------------
    | SMS
    |--------------------------------------------------------------------------
    |
    | Main SMS transport endpoint.
    |
    | Physical file:
    |
    |     listeners/Sms/SMSListener.php
    |
    | Namespace:
    |
    |     Listeners\Sms
    |
    | Class:
    |
    |     SmsListener
    |
    */

    '/sms' =>
        SmsListener::class,


    /*
    |--------------------------------------------------------------------------
    | SMS Incoming Webhook
    |--------------------------------------------------------------------------
    |
    | Provider webhook endpoint.
    |
    | Physical file:
    |
    |     listeners/Sms/SmsWebhookListener.php
    |
    */

    '/sms/webhook' =>
        SmsWebhookListener::class,


    /*
    |--------------------------------------------------------------------------
    | SMS Compatibility Webhook
    |--------------------------------------------------------------------------
    |
    | Alternative provider webhook endpoint.
    |
    */

    '/webhook/sms' =>
        SmsWebhookListener::class,


    /*
    |--------------------------------------------------------------------------
    | USSD
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | Physical folder:
    |
    |     listeners/Ussd/
    |
    | Namespace:
    |
    |     Listeners\Ussd
    |
    | Class:
    |
    |     UssdListener
    |
    */

    '/ussd' =>
        UssdListener::class,


    /*
    |--------------------------------------------------------------------------
    | Universal Web Listener
    |--------------------------------------------------------------------------
    */

    '/web' =>
        WebListener::class,


    /*
    |--------------------------------------------------------------------------
    | Universal API Compatibility Endpoint
    |--------------------------------------------------------------------------
    */

    '/api/web' =>
        WebListener::class,


    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    */

    '/telegram' =>
        TelegramListener::class,


    /*
    |--------------------------------------------------------------------------
    | WhatsApp
    |--------------------------------------------------------------------------
    */

    '/whatsapp' =>
        WhatsAppListener::class,


    /*
    |--------------------------------------------------------------------------
    | ADVERT PAYMENTS
    |--------------------------------------------------------------------------
    |
    | Existing Paystack advert-payment infrastructure.
    |
    */

    '/payment/paystack/advert/callback' =>
        PaystackListener::class,


    '/payment/paystack/advert/webhook' =>
        PaystackWebhookListener::class,


    /*
    |--------------------------------------------------------------------------
    | ESCROW PAYSTACK WEBHOOK
    |--------------------------------------------------------------------------
    */

    '/payment/paystack/escrow/webhook' =>
        EscrowPaystackWebhookListener::class,


    /*
    |--------------------------------------------------------------------------
    | ESCROW API
    |--------------------------------------------------------------------------
    */


    /*
    |--------------------------------------------------------------------------
    | Verify Escrow
    |--------------------------------------------------------------------------
    |
    | POST /api/escrow/verify
    |
    */

    '/api/escrow/verify' =>
    [
        EscrowApiController::class,
        'verify',
    ],


    /*
    |--------------------------------------------------------------------------
    | Release / Confirm Receipt
    |--------------------------------------------------------------------------
    |
    | POST /api/escrow/release
    |
    */

    '/api/escrow/release' =>
    [
        EscrowApiController::class,
        'release',
    ],


    /*
    |--------------------------------------------------------------------------
    | Escrow Payment
    |--------------------------------------------------------------------------
    |
    | POST /api/escrow/payment
    |
    */

    '/api/escrow/payment' =>
    [
        EscrowApiController::class,
        'payment',
    ],


    /*
    |--------------------------------------------------------------------------
    | Escrow Payment Status
    |--------------------------------------------------------------------------
    |
    | GET/POST /api/escrow/payment/status
    |
    */

    '/api/escrow/payment/status' =>
    [
        EscrowApiController::class,
        'paymentStatus',
    ],


    /*
    |--------------------------------------------------------------------------
    | ADMIN ESCROW
    |--------------------------------------------------------------------------
    */

    '/admin/escrow' =>
        AdminEscrowListener::class,


    '/admin/escrow_payout' =>
        AdminEscrowListener::class,


    '/admin/escrow_payout.php' =>
        AdminEscrowListener::class,

];
?>
