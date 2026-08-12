<?php

declare(strict_types=1);

namespace Services\Adapters\SMS;

use Core\Logger;
use Throwable;

/**
 * ---------------------------------------------------------
 * TWILIO SMS PROVIDER
 * ---------------------------------------------------------
 *
 * Handles:
 *
 * - Outgoing SMS
 * - Incoming Twilio webhook normalization
 * - Twilio webhook validation
 * - Provider health check
 *
 * This class contains ONLY Twilio-specific logic.
 *
 * The rest of the application communicates through:
 *
 * SmsProviderInterface
 *
 * ---------------------------------------------------------
 */
class TwilioSmsProvider implements SmsProviderInterface
{
    protected string $accountSid;

    protected string $authToken;

    protected string $from;

    protected string $endpoint;


    /**
     * ---------------------------------------------------------
     * Constructor
     * ---------------------------------------------------------
     */
    public function __construct()
    {
        $this->accountSid =
            defined('TWILIO_ACCOUNT_SID')
                ? (string) TWILIO_ACCOUNT_SID
                : '';

        $this->authToken =
            defined('TWILIO_AUTH_TOKEN')
                ? (string) TWILIO_AUTH_TOKEN
                : '';

        $this->from =
            defined('TWILIO_SMS_FROM')
                ? (string) TWILIO_SMS_FROM
                : '';

        $this->endpoint =
            'https://api.twilio.com/2010-04-01/Accounts/'
            . $this->accountSid
            . '/Messages.json';


        Logger::write(
            'twilio_sms_provider',
            [
                'step' => 'CONSTRUCTOR',
                'configured' =>
                    $this->isConfigured()
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * Send SMS
     * ---------------------------------------------------------
     */
    public function send(
        string $to,
        string $message
    ): array {

        try {

            $to =
                $this->normalizePhone(
                    $to
                );

            $message =
                trim($message);


            Logger::write(
                'twilio_sms_provider',
                [
                    'step' => 'SEND_START',
                    'to' => $to,
                    'message_length' =>
                        strlen($message)
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Validate Configuration
            |--------------------------------------------------------------------------
            */

            if (
                !$this->isConfigured()
            ) {

                Logger::write(
                    'twilio_sms_provider',
                    [
                        'step' =>
                            'SEND_NOT_CONFIGURED'
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Twilio SMS provider is not configured.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Recipient
            |--------------------------------------------------------------------------
            */

            if (
                $to === ''
            ) {

                return [
                    'success' => false,
                    'message' =>
                        'Recipient phone number is required.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Message
            |--------------------------------------------------------------------------
            */

            if (
                $message === ''
            ) {

                return [
                    'success' => false,
                    'message' =>
                        'SMS message is required.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Build POST Data
            |--------------------------------------------------------------------------
            */

            $payload = http_build_query(
                [
                    'To' =>
                        $to,

                    'From' =>
                        $this->from,

                    'Body' =>
                        $message
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | cURL
            |--------------------------------------------------------------------------
            */

            $ch =
                curl_init(
                    $this->endpoint
                );


            curl_setopt_array(
                $ch,
                [
                    CURLOPT_RETURNTRANSFER =>
                        true,

                    CURLOPT_POST =>
                        true,

                    CURLOPT_POSTFIELDS =>
                        $payload,

                    CURLOPT_USERPWD =>
                        $this->accountSid
                        . ':'
                        . $this->authToken,

                    CURLOPT_HTTPAUTH =>
                        CURLAUTH_BASIC,

                    CURLOPT_CONNECTTIMEOUT =>
                        10,

                    CURLOPT_TIMEOUT =>
                        60,

                    CURLOPT_HTTPHEADER =>
                        [
                            'Content-Type: application/x-www-form-urlencoded'
                        ]
                ]
            );


            $response =
                curl_exec(
                    $ch
                );


            $httpCode =
                curl_getinfo(
                    $ch,
                    CURLINFO_HTTP_CODE
                );


            $curlError =
                curl_error(
                    $ch
                );


            curl_close(
                $ch
            );


            Logger::write(
                'twilio_sms_provider',
                [
                    'step' =>
                        'SEND_RESPONSE',

                    'http_code' =>
                        $httpCode,

                    'curl_error' =>
                        $curlError,

                    'response' =>
                        $response
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | cURL Error
            |--------------------------------------------------------------------------
            */

            if (
                $curlError !== ''
            ) {

                return [
                    'success' => false,
                    'message' =>
                        $curlError
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Decode Response
            |--------------------------------------------------------------------------
            */

            $decoded =
                json_decode(
                    (string)$response,
                    true
                );


            /*
            |--------------------------------------------------------------------------
            | HTTP Failure
            |--------------------------------------------------------------------------
            */

            if (
                $httpCode < 200
                ||
                $httpCode >= 300
            ) {

                return [
                    'success' => false,

                    'message' =>
                        $decoded['message']
                        ??
                        'Twilio failed to send SMS.',

                    'raw' =>
                        $decoded
                        ??
                        $response
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Success
            |--------------------------------------------------------------------------
            */

            return [
                'success' => true,

                'message_id' =>
                    $decoded['sid']
                    ?? null,

                'status' =>
                    $decoded['status']
                    ?? null,

                'provider' =>
                    $this->name(),

                'raw' =>
                    $decoded
            ];

        }
        catch (Throwable $e) {

            Logger::write(
                'twilio_sms_provider_error',
                [
                    'step' =>
                        'SEND_EXCEPTION',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine()
                ]
            );


            return [
                'success' => false,

                'message' =>
                    'Unable to send SMS.'
            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * Parse Incoming Twilio Webhook
     * ---------------------------------------------------------
     *
     * Twilio normally posts form-encoded values such as:
     *
     * From
     * To
     * Body
     * MessageSid
     *
     * We normalize these into our common SMS structure.
     * ---------------------------------------------------------
     */
    public function incoming(
        array $request
    ): array {

        try {

            Logger::write(
                'twilio_sms_provider',
                [
                    'step' =>
                        'INCOMING_START',

                    'keys' =>
                        array_keys($request)
                ]
            );


            $phone =
                $request['From']
                ??
                $request['from']
                ??
                '';


            $message =
                $request['Body']
                ??
                $request['body']
                ??
                '';


            $messageId =
                $request['MessageSid']
                ??
                $request['SmsMessageSid']
                ??
                $request['message_sid']
                ??
                null;


            $phone =
                $this->normalizePhone(
                    (string)$phone
                );


            $message =
                trim(
                    (string)$message
                );


            /*
            |--------------------------------------------------------------------------
            | Validate
            |--------------------------------------------------------------------------
            */

            if (
                $phone === ''
            ) {

                return [
                    'success' => false,

                    'message' =>
                        'Incoming SMS sender number is missing.'
                ];
            }


            if (
                $message === ''
            ) {

                return [
                    'success' => false,

                    'message' =>
                        'Incoming SMS message is empty.'
                ];
            }


            $normalized = [

                'success' =>
                    true,

                'phone' =>
                    $phone,

                'message' =>
                    $message,

                'message_id' =>
                    $messageId,

                'provider' =>
                    $this->name(),

                'raw' =>
                    $request
            ];


            Logger::write(
                'twilio_sms_provider',
                [
                    'step' =>
                        'INCOMING_NORMALIZED',

                    'phone' =>
                        $phone,

                    'message' =>
                        $message,

                    'message_id' =>
                        $messageId
                ]
            );


            return $normalized;

        }
        catch (Throwable $e) {

            Logger::write(
                'twilio_sms_provider_error',
                [
                    'step' =>
                        'INCOMING_EXCEPTION',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine()
                ]
            );


            return [
                'success' => false,

                'message' =>
                    'Unable to process incoming SMS.'
            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * Verify Twilio Webhook
     * ---------------------------------------------------------
     *
     * Twilio signs webhook requests using:
     *
     * X-Twilio-Signature
     *
     * We validate the signature against the configured
     * webhook URL and POST parameters.
     *
     * ---------------------------------------------------------
     */
    public function verifyWebhook(
        array $request,
        array $server = []
    ): bool {

        try {

            /*
            |--------------------------------------------------------------------------
            | Signature
            |--------------------------------------------------------------------------
            */

            $signature =
                $server['HTTP_X_TWILIO_SIGNATURE']
                ??
                $server['X-Twilio-Signature']
                ??
                '';


            /*
            |--------------------------------------------------------------------------
            | If no signature exists, reject
            |--------------------------------------------------------------------------
            */

            if (
                trim($signature) === ''
            ) {

                Logger::write(
                    'twilio_sms_provider',
                    [
                        'step' =>
                            'WEBHOOK_SIGNATURE_MISSING'
                    ]
                );

                return false;
            }


            /*
            |--------------------------------------------------------------------------
            | Auth Token
            |--------------------------------------------------------------------------
            */

            if (
                $this->authToken === ''
            ) {

                Logger::write(
                    'twilio_sms_provider',
                    [
                        'step' =>
                            'WEBHOOK_AUTH_TOKEN_MISSING'
                    ]
                );

                return false;
            }


            /*
            |--------------------------------------------------------------------------
            | Build Webhook URL
            |--------------------------------------------------------------------------
            */

            $scheme =
                $server['HTTP_X_FORWARDED_PROTO']
                ??
                $server['REQUEST_SCHEME']
                ??
                (
                    (!empty($server['HTTPS'])
                    &&
                    $server['HTTPS'] !== 'off')
                        ? 'https'
                        : 'http'
                );


            $host =
                $server['HTTP_HOST']
                ??
                '';


            $uri =
                $server['REQUEST_URI']
                ??
                '';


            if (
                $host === ''
                ||
                $uri === ''
            ) {

                return false;
            }


            $url =
                $scheme
                . '://'
                . $host
                . $uri;


            /*
            |--------------------------------------------------------------------------
            | Twilio Signature Payload
            |--------------------------------------------------------------------------
            |
            | Twilio signs:
            |
            | URL + sorted POST parameter names/values.
            |
            */

            $data =
                $url;


            $params =
                $request;


            ksort(
                $params
            );


            foreach (
                $params as $key => $value
            ) {

                if (
                    is_array($value)
                ) {

                    continue;
                }


                $data .=
                    $key
                    .
                    (string)$value;
            }


            /*
            |--------------------------------------------------------------------------
            | Calculate Signature
            |--------------------------------------------------------------------------
            */

            $expected =
                base64_encode(
                    hash_hmac(
                        'sha1',
                        $data,
                        $this->authToken,
                        true
                    )
                );


            $valid =
                hash_equals(
                    $expected,
                    $signature
                );


            Logger::write(
                'twilio_sms_provider',
                [
                    'step' =>
                        'WEBHOOK_SIGNATURE_CHECK',

                    'valid' =>
                        $valid
                ]
            );


            return $valid;

        }
        catch (Throwable $e) {

            Logger::write(
                'twilio_sms_provider_error',
                [
                    'step' =>
                        'WEBHOOK_VERIFY_EXCEPTION',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine()
                ]
            );


            return false;
        }
    }


    /**
     * ---------------------------------------------------------
     * Provider Name
     * ---------------------------------------------------------
     */
    public function name(): string
    {
        return 'twilio';
    }


    /**
     * ---------------------------------------------------------
     * Health Check
     * ---------------------------------------------------------
     */
    public function health(): array
    {
        try {

            if (
                !$this->isConfigured()
            ) {

                return [
                    'success' => false,

                    'provider' =>
                        $this->name(),

                    'message' =>
                        'Twilio credentials are not configured.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | We deliberately do not send a real SMS here.
            |--------------------------------------------------------------------------
            |
            | Health means the required configuration exists.
            | A real API connectivity test can be added to the admin
            | test action later.
            |
            */

            return [
                'success' => true,

                'provider' =>
                    $this->name(),

                'message' =>
                    'Twilio SMS configuration is present.'
            ];

        }
        catch (Throwable $e) {

            Logger::write(
                'twilio_sms_provider_error',
                [
                    'step' =>
                        'HEALTH_EXCEPTION',

                    'message' =>
                        $e->getMessage()
                ]
            );


            return [
                'success' => false,

                'provider' =>
                    $this->name(),

                'message' =>
                    'Twilio health check failed.'
            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * Configuration Check
     * ---------------------------------------------------------
     */
    protected function isConfigured(): bool
    {
        return
            $this->accountSid !== ''
            &&
            $this->authToken !== ''
            &&
            $this->from !== '';
    }


    /**
     * ---------------------------------------------------------
     * Normalize Phone
     * ---------------------------------------------------------
     *
     * Internal representation:
     *
     * 2348012345678
     *
     * No:
     *
     * +
     * spaces
     * brackets
     * hyphens
     * whatsapp:
     *
     * ---------------------------------------------------------
     */
    protected function normalizePhone(
        string $phone
    ): string {

        $phone =
            trim(
                $phone
            );


        $phone =
            str_replace(
                'whatsapp:',
                '',
                $phone
            );


        $phone =
            str_replace(
                '+',
                '',
                $phone
            );


        $phone =
            preg_replace(
                '/[^0-9]/',
                '',
                $phone
            )
            ?? '';


        /*
        |--------------------------------------------------------------------------
        | Nigerian Local Number
        |--------------------------------------------------------------------------
        */

        if (
            str_starts_with(
                $phone,
                '0'
            )
            &&
            strlen($phone) === 11
        ) {

            $phone =
                '234'
                .
                substr(
                    $phone,
                    1
                );
        }


        return $phone;
    }
}


### Configuration expected

This adapter expects these constants to exist:

```php
TWILIO_ACCOUNT_SID
TWILIO_AUTH_TOKEN
TWILIO_SMS_FROM
```

We are **not** adding provider selection into this class. That belongs in the factory/configuration layer.

### Important security point

The incoming SMS is normalized to:

```text
phone = 2348012345678
message = VERIFY SDM-000033
provider = twilio
```

The listener will then pass that to the existing API service.

For:

```text
RECEIVED SDM-000033
```

the originating phone number follows the request all the way to:

```php
EscrowApiService::confirmReceipt(
    $reference,
    $phone
);
```

So the existing buyer authorization remains the security boundary.

**Next file:**

```text
services/Adapters/SMS/TermiiSmsProvider.php
```

Then Africa's Talking, Arkesel, the factory, and finally the single `SmsListener.php`.
