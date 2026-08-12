<?php

declare(strict_types=1);

namespace Services\USSD\Providers;

use Core\Logger;
use Throwable;

class ArkeselUssdProvider implements USSDProviderInterface
{
    protected string $apiKey;

    protected string $baseUrl;

    protected string $serviceCode;

    protected string $senderId;


    public function __construct()
    {
        $this->apiKey =
            defined('ARKESEL_API_KEY')
                ? (string) ARKESEL_API_KEY
                : '';


        $this->baseUrl =
            defined('ARKESEL_BASE_URL')
                ? rtrim(
                    (string) ARKESEL_BASE_URL,
                    '/'
                )
                : 'https://sms.arkesel.com';


        $this->serviceCode =
            defined('USSD_SERVICE_CODE')
                ? (string) USSD_SERVICE_CODE
                : '';


        $this->senderId =
            defined('ARKESEL_SENDER_ID')
                ? (string) ARKESEL_SENDER_ID
                : '';


        Logger::write(
            'arkesel_ussd_provider',
            [
                'step' =>
                    'CONSTRUCTOR',

                'base_url' =>
                    $this->baseUrl,

                'service_code' =>
                    $this->serviceCode,

                'sender_id' =>
                    $this->senderId,
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
        return 'arkesel';
    }


    /**
     * ---------------------------------------------------------
     * Configuration Check
     * ---------------------------------------------------------
     */
    public function configured(): bool
    {
        return $this->apiKey !== '';
    }


    /**
     * ---------------------------------------------------------
     * Normalize Incoming Request
     * ---------------------------------------------------------
     *
     * Arkesel payload structures can vary depending on the
     * service configured. We therefore accept the common
     * field aliases and normalize them into the application
     * USSD structure.
     */
    public function normalizeIncoming(
        array $payload
    ): array {

        try {

            $phone =
                $payload['phoneNumber']
                ??
                $payload['phone']
                ??
                $payload['msisdn']
                ??
                $payload['mobile']
                ??
                $payload['from']
                ??
                '';


            $sessionId =
                $payload['sessionId']
                ??
                $payload['session_id']
                ??
                $payload['session']
                ??
                $payload['request_id']
                ??
                '';


            $serviceCode =
                $payload['serviceCode']
                ??
                $payload['service_code']
                ??
                $payload['ussd_code']
                ??
                $payload['code']
                ??
                '';


            $text =
                $payload['text']
                ??
                $payload['message']
                ??
                $payload['body']
                ??
                $payload['input']
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
                    $payload['network']
                    ??
                    $payload['networkCode']
                    ??
                    null,

                'raw' =>
                    $payload,

            ];
        }
        catch (Throwable $e) {

            Logger::write(
                'arkesel_ussd_error',
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
     */
    public function formatResponse(
        string $message,
        bool $continue = false
    ): string {

        $message =
            trim(
                $message
            );


        /*
        |--------------------------------------------------------------------------
        | Remove Existing Prefix
        |--------------------------------------------------------------------------
        */

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


        /*
        |--------------------------------------------------------------------------
        | Response Length
        |--------------------------------------------------------------------------
        */

        $maxLength =
            500;


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


        return
            ($continue ? 'CON ' : 'END ')
            .
            $message;
    }


    /**
     * ---------------------------------------------------------
     * Validate Webhook
     * ---------------------------------------------------------
     */
    public function validateWebhook(
        array $headers = [],
        ?string $rawBody = null
    ): bool {

        try {

            $secret =
                defined('USSD_WEBHOOK_SECRET')
                    ? trim(
                        (string)USSD_WEBHOOK_SECRET
                    )
                    : '';


            /*
            |--------------------------------------------------------------------------
            | Optional Application Secret
            |--------------------------------------------------------------------------
            */

            if ($secret === '') {

                return true;
            }


            $received =
                $headers['X-USSD-SECRET']
                ??
                $headers['X-Webhook-Secret']
                ??
                $headers['x-ussd-secret']
                ??
                $headers['x-webhook-secret']
                ??
                '';


            if (
                !is_string($received)
                ||
                trim($received) === ''
            ) {

                return false;
            }


            return hash_equals(
                $secret,
                trim(
                    $received
                )
            );
        }
        catch (Throwable $e) {

            Logger::write(
                'arkesel_ussd_error',
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
     * USSD is normally session/callback driven.
     *
     * The active USSD callback should return the formatted
     * response directly instead of attempting to initiate
     * an unrelated SMS/API request here.
     */
    public function send(
        string $phone,
        string $message,
        array $context = []
    ): array {

        Logger::write(
            'arkesel_ussd_provider',
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
                'Arkesel USSD responses must be returned through the active USSD callback.',

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

            'base_url' =>
                $this->baseUrl,

            'service_code' =>
                $this->serviceCode,

            'sender_id' =>
                $this->senderId,

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
}
?>
