<?php

declare(strict_types=1);

namespace Services\USSD\Providers;

use Core\Logger;
use Throwable;

class TwilioUssdProvider implements USSDProviderInterface
{
    protected string $accountSid;

    protected string $authToken;

    protected string $phoneNumber;

    protected string $baseUrl;


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


        $this->phoneNumber =
            defined('TWILIO_PHONE_NUMBER')
                ? (string) TWILIO_PHONE_NUMBER
                : '';


        $this->baseUrl =
            'https://api.twilio.com';


        Logger::write(
            'twilio_ussd_provider',
            [
                'step' =>
                    'CONSTRUCTOR',

                'account_sid' =>
                    $this->accountSid !== ''
                        ? substr(
                            $this->accountSid,
                            0,
                            8
                        ) . '...'
                        : '',

                'phone_number' =>
                    $this->phoneNumber,

                'base_url' =>
                    $this->baseUrl,
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * Provider Identity
     * ---------------------------------------------------------
     */
    public function name(): string
    {
        return 'twilio';
    }


    /**
     * ---------------------------------------------------------
     * Configuration Check
     * ---------------------------------------------------------
     */
    public function configured(): bool
    {
        return
            $this->accountSid !== ''
            &&
            $this->authToken !== ''
            &&
            $this->phoneNumber !== '';
    }


    /**
     * ---------------------------------------------------------
     * Normalize Incoming USSD Request
     * ---------------------------------------------------------
     *
     * Twilio-style webhook fields are normalized into the
     * application's common USSD structure.
     */
    public function normalizeIncoming(
        array $payload
    ): array {

        try {

            $phone =
                $payload['From']
                ??
                $payload['from']
                ??
                $payload['phoneNumber']
                ??
                $payload['phone']
                ??
                '';


            $sessionId =
                $payload['CallSid']
                ??
                $payload['SessionId']
                ??
                $payload['session_id']
                ??
                $payload['sessionId']
                ??
                '';


            $serviceCode =
                $payload['To']
                ??
                $payload['serviceCode']
                ??
                $payload['service_code']
                ??
                '';


            $text =
                $payload['Body']
                ??
                $payload['text']
                ??
                '';


            return [

                'provider' =>
                    $this->name(),

                'platform' =>
                    'ussd',

                'session_id' =>
                    trim(
                        (string)$sessionId
                    ),

                'phone' =>
                    $this->normalizePhone(
                        (string)$phone
                    ),

                'service_code' =>
                    trim(
                        (string)$serviceCode
                    ),

                'text' =>
                    trim(
                        (string)$text
                    ),

                'network' =>
                    $payload['Network']
                    ??
                    $payload['network']
                    ??
                    null,

                'raw' =>
                    $payload,

            ];
        }
        catch (Throwable $e) {

            Logger::write(
                'twilio_ussd_error',
                [
                    'step' =>
                        'NORMALIZE_INCOMING_FAILED',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );


            return [

                'provider' =>
                    $this->name(),

                'platform' =>
                    'ussd',

                'session_id' =>
                    '',

                'phone' =>
                    '',

                'service_code' =>
                    '',

                'text' =>
                    '',

                'network' =>
                    null,

                'raw' =>
                    $payload,

                'error' =>
                    true,

            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * Format USSD Response
     * ---------------------------------------------------------
     *
     * Twilio normally expects a TwiML response rather than
     * the raw CON / END format used by African USSD gateways.
     *
     * We therefore translate the common application response
     * into TwiML.
     */
    public function formatResponse(
        string $message,
        bool $continue = false
    ): string {

        $message =
            trim(
                $message
            );


        $message =
            preg_replace(
                '/^(CON|END)\s+/i',
                '',
                $message
            )
            ??
            $message;


        if ($message === '') {

            $message =
                $continue
                    ? 'Please continue.'
                    : 'Request completed.';
        }


        $maxLength = 500;


        if (
            defined('USSD_MAX_RESPONSE_LENGTH')
            &&
            USSD_MAX_RESPONSE_LENGTH > 0
        ) {

            $maxLength =
                min(
                    500,
                    (int)USSD_MAX_RESPONSE_LENGTH
                );
        }


        if (
            strlen($message)
            >
            $maxLength
        ) {

            $message =
                substr(
                    $message,
                    0,
                    $maxLength
                );
        }


        /*
        |--------------------------------------------------------------------------
        | Escape XML
        |--------------------------------------------------------------------------
        */

        $message =
            htmlspecialchars(
                $message,
                ENT_XML1 | ENT_QUOTES,
                'UTF-8'
            );


        /*
        |--------------------------------------------------------------------------
        | Build TwiML
        |--------------------------------------------------------------------------
        |
        | <Gather> keeps the interaction open when the
        | application expects another response.
        |
        */

        if ($continue) {

            return
                '<?xml version="1.0" encoding="UTF-8"?>'

```
            .
            '<Response>'
            .
            '<Gather input="dtmf" method="POST">'
            .
            '<Say>'
            .
            $message
            .
            '</Say>'
            .
            '</Gather>'
            .
            '</Response>';
    }


    return
        '<?xml version="1.0" encoding="UTF-8"?>'
        .
        '<Response>'
        .
        '<Say>'
        .
        $message
        .
        '</Say>'
        .
        '</Response>';
}


/**
 * ---------------------------------------------------------
 * Validate Webhook
 * ---------------------------------------------------------
 *
 * Supports Twilio's X-Twilio-Signature validation.
 */
public function validateWebhook(
    array $headers = [],
    ?string $rawBody = null
): bool {

    try {

        /*
        |--------------------------------------------------------------------------
        | If Twilio credentials are unavailable, fail closed
        |--------------------------------------------------------------------------
        */

        if (
            $this->accountSid === ''
            ||
            $this->authToken === ''
        ) {

            return false;
        }


        $signature =
            $headers['X-Twilio-Signature']
            ??
            $headers['x-twilio-signature']
            ??
            '';


        if (
            !is_string($signature)
            ||
            trim($signature) === ''
        ) {

            /*
            |--------------------------------------------------------------------------
            | Optional application secret fallback
            |--------------------------------------------------------------------------
            */

            $secret =
                defined('USSD_WEBHOOK_SECRET')
                    ? trim(
                        (string)USSD_WEBHOOK_SECRET
                    )
                    : '';


            if ($secret === '') {

                return false;
            }


            $receivedSecret =
                $headers['X-USSD-SECRET']
                ??
                $headers['X-Webhook-Secret']
                ??
                $headers['x-ussd-secret']
                ??
                $headers['x-webhook-secret']
                ??
                '';


            return
                is_string(
                    $receivedSecret
                )
                &&
                $receivedSecret !== ''
                &&
                hash_equals(
                    $secret,
                    trim(
                        $receivedSecret
                    )
                );
        }


        /*
        |--------------------------------------------------------------------------
        | Determine Webhook URL
        |--------------------------------------------------------------------------
        */

        $url =
            '';

        if (
            isset(
                $_SERVER['HTTPS']
            )
            &&
            $_SERVER['HTTPS'] !== 'off'
        ) {

            $scheme = 'https';

        }
        else {

            $scheme = 'http';
        }


        $host =
            $_SERVER['HTTP_HOST']
            ??
            '';


        $requestUri =
            $_SERVER['REQUEST_URI']
            ??
            '';


        if (
            $host !== ''
            &&
            $requestUri !== ''
        ) {

            $url =
                $scheme
                .
                '://'
                .
                $host
                .
                $requestUri;
        }


        if ($url === '') {

            $url =
                defined('APP_URL')
                    ? rtrim(
                        (string)APP_URL,
                        '/'
                    )
                    .
                    '/ussd'
                    : '';
        }


        if ($url === '') {

            return false;
        }


        /*
        |--------------------------------------------------------------------------
        | Build Twilio Signature
        |--------------------------------------------------------------------------
        */

        $params = $_POST;

        ksort(
            $params
        );


        $data =
            $url;


        foreach (
            $params
            as $key => $value
        ) {

            if (
                is_array($value)
            ) {

                $value =
                    implode(
                        '',
                        $value
                    );
            }


            $data .=
                $key
                .
                $value;
        }


        $expected =
            base64_encode(
                hash_hmac(
                    'sha1',
                    $data,
                    $this->authToken,
                    true
                )
            );


        return hash_equals(
            $expected,
            trim(
                $signature
            )
        );
    }
    catch (Throwable $e) {

        Logger::write(
            'twilio_ussd_error',
            [
                'step' =>
                    'WEBHOOK_VALIDATION_FAILED',

                'message' =>
                    $e->getMessage(),

                'file' =>
                    $e->getFile(),

                'line' =>
                    $e->getLine(),
            ]
        );


        return false;
    }
}


/**
 * ---------------------------------------------------------
 * Send
 * ---------------------------------------------------------
 *
 * USSD sessions are normally initiated by the network
 * and answered through the active webhook.
 *
 * This method therefore does not attempt to create a
 * standalone USSD session through Twilio's SMS API.
 */
public function send(
    string $phone,
    string $message,
    array $context = []
): array {

    Logger::write(
        'twilio_ussd_provider',
        [
            'step' =>
                'SEND_REQUEST',

            'phone' =>
                $phone,

            'message' =>
                $message,

            'context' =>
                $context,
        ]
    );


    return [

        'success' =>
            false,

        'provider' =>
            $this->name(),

        'message' =>
            'Twilio USSD responses must be returned through the active webhook session.',

    ];
}


/**
 * ---------------------------------------------------------
 * Health Check
 * ---------------------------------------------------------
 */
public function health(): array
{
    return [

        'provider' =>
            $this->name(),

        'configured' =>
            $this->configured(),

        'account_sid' =>
            $this->accountSid !== ''
                ? substr(
                    $this->accountSid,
                    0,
                    8
                ) . '...'
                : '',

        'phone_number' =>
            $this->phoneNumber,

        'base_url' =>
            $this->baseUrl,

    ];
}


/**
 * ---------------------------------------------------------
 * Normalize Phone
 * ---------------------------------------------------------
 */
protected function normalizePhone(
    string $phone
): string {

    $phone =
        trim(
            $phone
        );


    /*
    |--------------------------------------------------------------------------
    | Remove WhatsApp Prefix If Present
    |--------------------------------------------------------------------------
    */

    if (
        str_starts_with(
            strtolower($phone),
            'whatsapp:'
        )
    ) {

        $phone =
            substr(
                $phone,
                9
            );
    }


    /*
    |--------------------------------------------------------------------------
    | Keep Digits and Plus
    |--------------------------------------------------------------------------
    */

    $phone =
        preg_replace(
            '/[^0-9+]/',
            '',
            $phone
        )
        ??
        '';


    $phone =
        ltrim(
            $phone,
            '+'
        );


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
```

}
?>
