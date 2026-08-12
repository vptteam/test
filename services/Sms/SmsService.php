<?php

declare(strict_types=1);

namespace Services\SMS;

use Core\Logger;
use Throwable;

/**
 * ==========================================================================
 * SENDAM SMS SERVICE
 * ==========================================================================
 *
 * Central outgoing SMS service.
 *
 * Supported providers:
 *
 *     twilio
 *     termii
 *     africastalking
 *     arkesel
 *
 * The active provider is selected from:
 *
 *     SMS_PROVIDER
 *
 * Example:
 *
 *     SMS_PROVIDER = termii
 *
 * Usage:
 *
 *     $sms = new SmsService();
 *
 *     $sms->send(
 *         '08012345678',
 *         'Your escrow has been confirmed.'
 *     );
 *
 * ==========================================================================
 */
class SmsService
{
    protected string $provider;

    protected int $timeout;

    public function __construct()
    {
        $this->provider =
            strtolower(
                trim(
                    defined('SMS_PROVIDER')
                        ? (string)SMS_PROVIDER
                        : 'termii'
                )
            );

        $this->timeout =
            defined('SMS_TIMEOUT')
                ? max(
                    5,
                    (int)SMS_TIMEOUT
                )
                : 30;


        Logger::write(
            'sms_service',
            [
                'step'     => 'CONSTRUCTOR',
                'provider' => $this->provider,
                'timeout'  => $this->timeout
            ]
        );
    }


    /**
     * ----------------------------------------------------------------------
     * Send SMS
     * ----------------------------------------------------------------------
     */
    public function send(
        string $phone,
        string $message,
        array $options = []
    ): array {

        try {

            $phone =
                $this->normalizePhone(
                    $phone
                );

            $message =
                trim(
                    $message
                );


            Logger::write(
                'sms_service',
                [
                    'step'     => 'SEND_START',
                    'provider' => $this->provider,
                    'phone'    => $phone,
                    'length'   => strlen($message)
                ]
            );


            if ($phone === '') {

                return [
                    'success' => false,
                    'message' => 'Phone number is required.'
                ];
            }


            if ($message === '') {

                return [
                    'success' => false,
                    'message' => 'SMS message is required.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Respect SMS length configuration
            |--------------------------------------------------------------------------
            */

            $maxLength =
                defined('SMS_MAX_RESPONSE_LENGTH')
                    ? (int)SMS_MAX_RESPONSE_LENGTH
                    : 480;


            if (
                $maxLength > 0
                &&
                mb_strlen($message) > $maxLength
            ) {

                $message =
                    mb_substr(
                        $message,
                        0,
                        $maxLength
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | Provider selection
            |--------------------------------------------------------------------------
            */

            switch ($this->provider) {

                case 'twilio':

                    return $this->sendTwilio(
                        $phone,
                        $message,
                        $options
                    );


                case 'termii':

                    return $this->sendTermii(
                        $phone,
                        $message,
                        $options
                    );


                case 'africastalking':
                case 'africas_talking':
                case 'africa_talking':

                    return $this->sendAfricasTalking(
                        $phone,
                        $message,
                        $options
                    );


                case 'arkesel':

                    return $this->sendArkesel(
                        $phone,
                        $message,
                        $options
                    );


                default:

                    Logger::write(
                        'sms_service_error',
                        [
                            'step'     => 'UNKNOWN_PROVIDER',
                            'provider' => $this->provider
                        ]
                    );


                    return [
                        'success' => false,
                        'message' =>
                            'Unsupported SMS provider: '
                            . $this->provider
                    ];
            }

        }
        catch (Throwable $e) {

            Logger::write(
                'sms_service_error',
                [
                    'step'     => 'SEND_EXCEPTION',
                    'provider' => $this->provider,
                    'message'  => $e->getMessage(),
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine()
                ]
            );


            return [
                'success' => false,
                'message' => 'SMS sending failed.'
            ];
        }
    }


    /**
     * ----------------------------------------------------------------------
     * Twilio
     * ----------------------------------------------------------------------
     */
    protected function sendTwilio(
        string $phone,
        string $message,
        array $options = []
    ): array {

        $sid =
            defined('TWILIO_ACCOUNT_SID')
                ? trim(
                    (string)TWILIO_ACCOUNT_SID
                )
                : '';


        $token =
            defined('TWILIO_AUTH_TOKEN')
                ? trim(
                    (string)TWILIO_AUTH_TOKEN
                )
                : '';


        $from =
            defined('TWILIO_PHONE_NUMBER')
                ? trim(
                    (string)TWILIO_PHONE_NUMBER
                )
                : '';


        /*
        |--------------------------------------------------------------------------
        | Existing WhatsApp config compatibility
        |--------------------------------------------------------------------------
        */

        if (
            $from === ''
            &&
            defined('TWILIO_WHATSAPP_NUMBER')
        ) {

            $from =
                trim(
                    (string)TWILIO_WHATSAPP_NUMBER
                );
        }


        if (
            $sid === ''
            ||
            $token === ''
            ||
            $from === ''
        ) {

            return [
                'success' => false,
                'message' =>
                    'Twilio SMS configuration is incomplete.'
            ];
        }


        $url =
            'https://api.twilio.com/2010-04-01/Accounts/'
            . rawurlencode($sid)
            . '/Messages.json';


        /*
        |--------------------------------------------------------------------------
        | Twilio requires + international format
        |--------------------------------------------------------------------------
        */

        $destination =
            '+' . $phone;


        $payload = [
            'To'   => $destination,
            'From' => $from,
            'Body' => $message
        ];


        Logger::write(
            'sms_service',
            [
                'step'     => 'TWILIO_SEND_START',
                'phone'    => $phone,
                'provider' => 'twilio'
            ]
        );


        $response =
            $this->curl(
                $url,
                'POST',
                $payload,
                [
                    'Authorization: Basic '
                    .
                    base64_encode(
                        $sid . ':' . $token
                    )
                ],
                true
            );


        if (!$response['success']) {

            return $response;
        }


        $data =
            is_array($response['data'])
                ? $response['data']
                : [];


        return [
            'success' => true,
            'provider' => 'twilio',
            'message_id' =>
                $data['sid'] ?? null,
            'message' =>
                'SMS sent successfully.',
            'raw' =>
                $data
        ];
    }


    /**
     * ----------------------------------------------------------------------
     * Termii
     * ----------------------------------------------------------------------
     */
    protected function sendTermii(
        string $phone,
        string $message,
        array $options = []
    ): array {

        $apiKey =
            defined('TERMII_API_KEY')
                ? trim(
                    (string)TERMII_API_KEY
                )
                : '';


        $baseUrl =
            defined('TERMII_BASE_URL')
                ? rtrim(
                    (string)TERMII_BASE_URL,
                    '/'
                )
                : 'https://api.ng.termii.com';


        $sender =
            $options['sender']
            ??
            (
                defined('TERMII_SENDER_ID')
                    ? (string)TERMII_SENDER_ID
                    : (
                        defined('SMS_SENDER_ID')
                            ? (string)SMS_SENDER_ID
                            : 'PINGCHECKOUT'
                    )
            );


        if ($apiKey === '') {

            return [
                'success' => false,
                'message' =>
                    'Termii API key is not configured.'
            ];
        }


        $payload = [

            'to' =>
                $phone,

            'from' =>
                $sender,

            'sms' =>
                $message,

            'type' =>
                'plain',

            'channel' =>
                'generic',

            'api_key' =>
                $apiKey

        ];


        Logger::write(
            'sms_service',
            [
                'step'     => 'TERMII_SEND_START',
                'phone'    => $phone,
                'provider' => 'termii'
            ]
        );


        $response =
            $this->curl(
                $baseUrl . '/api/sms/send',
                'POST',
                $payload
            );


        if (!$response['success']) {

            return $response;
        }


        $data =
            is_array($response['data'])
                ? $response['data']
                : [];


        /*
        |--------------------------------------------------------------------------
        | Termii can return different response structures.
        |--------------------------------------------------------------------------
        */

        $providerStatus =
            strtolower(
                (string)(
                    $data['code']
                    ??
                    $data['status']
                    ??
                    ''
                )
            );


        if (
            isset($data['code'])
            &&
            in_array(
                $providerStatus,
                [
                    'ok',
                    '200',
                    'success'
                ],
                true
            )
        ) {

            return [
                'success' => true,
                'provider' => 'termii',
                'message_id' =>
                    $data['message_id']
                    ??
                    $data['messageId']
                    ??
                    null,
                'message' =>
                    'SMS sent successfully.',
                'raw' =>
                    $data
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | If HTTP request succeeded but provider reports an error
        |--------------------------------------------------------------------------
        */

        if (
            isset($data['status'])
            &&
            in_array(
                strtolower(
                    (string)$data['status']
                ),
                [
                    'success',
                    'sent'
                ],
                true
            )
        ) {

            return [
                'success' => true,
                'provider' => 'termii',
                'message_id' =>
                    $data['message_id']
                    ??
                    null,
                'message' =>
                    'SMS sent successfully.',
                'raw' =>
                    $data
            ];
        }


        return [
            'success' => false,
            'provider' => 'termii',
            'message' =>
                $data['message']
                ??
                'Termii rejected the SMS.',
            'raw' =>
                $data
        ];
    }


    /**
     * ----------------------------------------------------------------------
     * Africa's Talking
     * ----------------------------------------------------------------------
     */
    protected function sendAfricasTalking(
        string $phone,
        string $message,
        array $options = []
    ): array {

        $username =
            defined('AFRICASTALKING_USERNAME')
                ? trim(
                    (string)AFRICASTALKING_USERNAME
                )
                : '';


        $apiKey =
            defined('AFRICASTALKING_API_KEY')
                ? trim(
                    (string)AFRICASTALKING_API_KEY
                )
                : '';


        $baseUrl =
            defined('AFRICASTALKING_BASE_URL')
                ? rtrim(
                    (string)AFRICASTALKING_BASE_URL,
                    '/'
                )
                : 'https://api.africastalking.com';


        $sender =
            $options['sender']
            ??
            (
                defined('AFRICASTALKING_SENDER_ID')
                    ? (string)AFRICASTALKING_SENDER_ID
                    : (
                        defined('SMS_SENDER_ID')
                            ? (string)SMS_SENDER_ID
                            : ''
                    )
            );


        if (
            $username === ''
            ||
            $apiKey === ''
        ) {

            return [
                'success' => false,
                'message' =>
                    'Africa\'s Talking configuration is incomplete.'
            ];
        }


        $payload = [

            'username' =>
                $username,

            'to' =>
                '+' . $phone,

            'message' =>
                $message

        ];


        if ($sender !== '') {

            $payload['from'] =
                $sender;
        }


        Logger::write(
            'sms_service',
            [
                'step'     => 'AFRICASTALKING_SEND_START',
                'phone'    => $phone,
                'provider' => 'africastalking'
            ]
        );


        $response =
            $this->curl(
                $baseUrl . '/version1/messaging',
                'POST',
                $payload,
                [
                    'apiKey: ' . $apiKey
                ],
                true
            );


        if (!$response['success']) {

            return $response;
        }


        $data =
            is_array($response['data'])
                ? $response['data']
                : [];


        /*
        |--------------------------------------------------------------------------
        | Africa's Talking response
        |--------------------------------------------------------------------------
        */

        $recipient =
            $data['SMSMessageData']['Recipients'][0]
            ?? null;


        if (
            is_array($recipient)
        ) {

            $status =
                strtolower(
                    (string)(
                        $recipient['status']
                        ?? ''
                    )
                );


            if (
                str_contains(
                    $status,
                    'sent'
                )
                ||
                str_contains(
                    $status,
                    'success'
                )
            ) {

                return [
                    'success' => true,
                    'provider' =>
                        'africastalking',
                    'message_id' =>
                        $recipient['messageId']
                        ??
                        null,
                    'message' =>
                        'SMS sent successfully.',
                    'raw' =>
                        $data
                ];
            }


            return [
                'success' => false,
                'provider' =>
                    'africastalking',
                'message' =>
                    $recipient['status']
                    ??
                    'Africa\'s Talking rejected the SMS.',
                'raw' =>
                    $data
            ];
        }


        return [
            'success' => false,
            'provider' =>
                'africastalking',
            'message' =>
                'Unexpected Africa\'s Talking response.',
            'raw' =>
                $data
        ];
    }


    /**
     * ----------------------------------------------------------------------
     * Arkesel
     * ----------------------------------------------------------------------
     */
    protected function sendArkesel(
        string $phone,
        string $message,
        array $options = []
    ): array {

        $apiKey =
            defined('ARKESEL_API_KEY')
                ? trim(
                    (string)ARKESEL_API_KEY
                )
                : '';


        $baseUrl =
            defined('ARKESEL_BASE_URL')
                ? rtrim(
                    (string)ARKESEL_BASE_URL,
                    '/'
                )
                : 'https://sms.arkesel.com';


        $sender =
            $options['sender']
            ??
            (
                defined('ARKESEL_SENDER_ID')
                    ? (string)ARKESEL_SENDER_ID
                    : (
                        defined('SMS_SENDER_ID')
                            ? (string)SMS_SENDER_ID
                            : 'PINGCHECKOUT'
                    )
            );


        if ($apiKey === '') {

            return [
                'success' => false,
                'message' =>
                    'Arkesel API key is not configured.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Arkesel SMS endpoint
        |--------------------------------------------------------------------------
        |
        | Credentials are kept in config. The request is isolated here so
        | changes to the provider API do not affect the rest of Sendam.
        |
        */

        $payload = [

            'action' =>
                'send-sms',

            'api_key' =>
                $apiKey,

            'to' =>
                $phone,

            'from' =>
                $sender,

            'sms' =>
                $message

        ];


        Logger::write(
            'sms_service',
            [
                'step'     => 'ARKESEL_SEND_START',
                'phone'    => $phone,
                'provider' => 'arkesel'
            ]
        );


        $response =
            $this->curl(
                $baseUrl . '/api',
                'POST',
                $payload
            );


        if (!$response['success']) {

            return $response;
        }


        $data =
            is_array($response['data'])
                ? $response['data']
                : [];


        /*
        |--------------------------------------------------------------------------
        | Provider response handling
        |--------------------------------------------------------------------------
        */

        $status =
            strtolower(
                (string)(
                    $data['status']
                    ??
                    $data['code']
                    ??
                    ''
                )
            );


        if (
            in_array(
                $status,
                [
                    'success',
                    'sent',
                    'ok',
                    '200'
                ],
                true
            )
        ) {

            return [
                'success' => true,
                'provider' =>
                    'arkesel',
                'message_id' =>
                    $data['message_id']
                    ??
                    $data['messageId']
                    ??
                    null,
                'message' =>
                    'SMS sent successfully.',
                'raw' =>
                    $data
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Some Arkesel responses are plain-text.
        |--------------------------------------------------------------------------
        */

        if (
            isset($response['raw'])
            &&
            is_string($response['raw'])
        ) {

            $raw =
                strtolower(
                    trim(
                        $response['raw']
                    )
                );


            if (
                str_contains(
                    $raw,
                    'success'
                )
                ||
                str_contains(
                    $raw,
                    'sent'
                )
            ) {

                return [
                    'success' => true,
                    'provider' =>
                        'arkesel',
                    'message' =>
                        'SMS sent successfully.',
                    'raw' =>
                        $response['raw']
                ];
            }
        }


        return [
            'success' => false,
            'provider' =>
                'arkesel',
            'message' =>
                $data['message']
                ??
                'Arkesel rejected the SMS.',
            'raw' =>
                $data
        ];
    }


    /**
     * ----------------------------------------------------------------------
     * HTTP helper
     * ----------------------------------------------------------------------
     */
    protected function curl(
        string $url,
        string $method,
        array $payload = [],
        array $extraHeaders = [],
        bool $formEncoded = false
    ): array {

        try {

            $ch =
                curl_init(
                    $url
                );


            if ($ch === false) {

                return [
                    'success' => false,
                    'message' =>
                        'Unable to initialize HTTP client.'
                ];
            }


            $headers = [
                'Accept: application/json'
            ];


            foreach ($extraHeaders as $header) {

                $headers[] =
                    $header;
            }


            if ($formEncoded) {

                $body =
                    http_build_query(
                        $payload
                    );

                $headers[] =
                    'Content-Type: application/x-www-form-urlencoded';

            }
            else {

                $body =
                    json_encode(
                        $payload,
                        JSON_UNESCAPED_SLASHES
                        |
                        JSON_UNESCAPED_UNICODE
                    );

                $headers[] =
                    'Content-Type: application/json';
            }


            curl_setopt_array(
                $ch,
                [

                    CURLOPT_RETURNTRANSFER =>
                        true,

                    CURLOPT_CUSTOMREQUEST =>
                        strtoupper($method),

                    CURLOPT_POSTFIELDS =>
                        $body,

                    CURLOPT_HTTPHEADER =>
                        $headers,

                    CURLOPT_CONNECTTIMEOUT =>
                        10,

                    CURLOPT_TIMEOUT =>
                        $this->timeout,

                    CURLOPT_FOLLOWLOCATION =>
                        false,

                    CURLOPT_SSL_VERIFYPEER =>
                        true,

                    CURLOPT_SSL_VERIFYHOST =>
                        2

                ]
            );


            $response =
                curl_exec(
                    $ch
                );


            $httpCode =
                (int)curl_getinfo(
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
                'sms_service',
                [
                    'step'       => 'HTTP_RESPONSE',
                    'url'        => $url,
                    'http_code'  => $httpCode,
                    'curl_error' => $curlError !== ''
                        ? $curlError
                        : null
                ]
            );


            if (
                $response === false
            ) {

                return [
                    'success' => false,
                    'message' =>
                        $curlError
                        !== ''
                            ? $curlError
                            : 'HTTP request failed.'
                ];
            }


            $decoded =
                json_decode(
                    $response,
                    true
                );


            /*
            |--------------------------------------------------------------------------
            | HTTP failure
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
                        'SMS provider returned HTTP '
                        . $httpCode,
                    'http_code' =>
                        $httpCode,
                    'raw' =>
                        $response,
                    'data' =>
                        is_array($decoded)
                            ? $decoded
                            : []
                ];
            }


            return [
                'success' => true,
                'http_code' =>
                    $httpCode,
                'raw' =>
                    $response,
                'data' =>
                    is_array($decoded)
                        ? $decoded
                        : []
            ];

        }
        catch (Throwable $e) {

            Logger::write(
                'sms_service_error',
                [
                    'step'    => 'HTTP_EXCEPTION',
                    'url'     => $url,
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine()
                ]
            );


            return [
                'success' => false,
                'message' =>
                    'SMS provider request failed.'
            ];
        }
    }


    /**
     * ----------------------------------------------------------------------
     * Normalize phone
     * ----------------------------------------------------------------------
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
                [
                    'whatsapp:',
                    'sms:',
                    'tel:',
                    '+'
                ],
                '',
                strtolower($phone)
            );


        $phone =
            preg_replace(
                '/[^0-9]/',
                '',
                $phone
            )
            ?? '';


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


    /**
     * ----------------------------------------------------------------------
     * Current provider
     * ----------------------------------------------------------------------
     */
    public function provider(): string
    {
        return $this->provider;
    }


    /**
     * ----------------------------------------------------------------------
     * Provider availability
     * ----------------------------------------------------------------------
     */
    public function isConfigured(): bool
    {
        switch ($this->provider) {

            case 'twilio':

                return
                    defined('TWILIO_ACCOUNT_SID')
                    &&
                    defined('TWILIO_AUTH_TOKEN')
                    &&
                    defined('TWILIO_PHONE_NUMBER')
                    &&
                    trim(
                        (string)TWILIO_ACCOUNT_SID
                    ) !== ''
                    &&
                    trim(
                        (string)TWILIO_AUTH_TOKEN
                    ) !== ''
                    &&
                    trim(
                        (string)TWILIO_PHONE_NUMBER
                    ) !== '';


            case 'termii':

                return
                    defined('TERMII_API_KEY')
                    &&
                    trim(
                        (string)TERMII_API_KEY
                    ) !== '';


            case 'africastalking':
            case 'africas_talking':
            case 'africa_talking':

                return
                    defined('AFRICASTALKING_USERNAME')
                    &&
                    defined('AFRICASTALKING_API_KEY')
                    &&
                    trim(
                        (string)AFRICASTALKING_USERNAME
                    ) !== ''
                    &&
                    trim(
                        (string)AFRICASTALKING_API_KEY
                    ) !== '';


            case 'arkesel':

                return
                    defined('ARKESEL_API_KEY')
                    &&
                    trim(
                        (string)ARKESEL_API_KEY
                    ) !== '';
        }


        return false;
    }
}
