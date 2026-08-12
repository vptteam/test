<?php

declare(strict_types=1);

namespace Services\USSD\Providers;

use Core\Logger;
use Throwable;

class TermiiUssdProvider implements USSDProviderInterface
{
    protected string $apiKey;

    protected string $baseUrl;

    protected string $serviceCode;


    public function __construct()
    {
        $this->apiKey =
            defined('TERMII_API_KEY')
                ? (string)TERMII_API_KEY
                : '';


        $this->baseUrl =
            defined('TERMII_BASE_URL')
                ? rtrim(
                    (string)TERMII_BASE_URL,
                    '/'
                )
                : 'https://api.ng.termii.com';


        $this->serviceCode =
            defined('USSD_SERVICE_CODE')
                ? (string)USSD_SERVICE_CODE
                : '';


        Logger::write(
            'termii_ussd_provider',
            [
                'step' =>
                    'CONSTRUCTOR',

                'base_url' =>
                    $this->baseUrl,

                'service_code' =>
                    $this->serviceCode,
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
        return 'termii';
    }


    /**
     * ---------------------------------------------------------
     * Configuration
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
                '';


            $sessionId =
                $payload['sessionId']
                ??
                $payload['session_id']
                ??
                $payload['session']
                ??
                '';


            $serviceCode =
                $payload['serviceCode']
                ??
                $payload['service_code']
                ??
                $payload['ussd_code']
                ??
                '';


            $text =
                $payload['text']
                ??
                $payload['message']
                ??
                $payload['body']
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
                    null,

                'raw' =>
                    $payload,

            ];
        }
        catch (Throwable $e) {

            Logger::write(
                'termii_ussd_error',
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


        return
            ($continue ? 'CON ' : 'END ')
            .
            $message;
    }


    /**
     * ---------------------------------------------------------
     * Webhook Validation
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
            | Optional Application-Level Secret
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
                trim($received)
            );
        }
        catch (Throwable $e) {

            Logger::write(
                'termii_ussd_error',
                [
                    'step' =>
                        'WEBHOOK_VALIDATION_FAILED',

                    'message' =>
                        $e->getMessage(),
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
     * USSD is normally session/callback driven. Therefore the
     * active webhook response should be returned directly.
     */
    public function send(
        string $phone,
        string $message,
        array $context = []
    ): array {

        Logger::write(
            'termii_ussd_provider',
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
                'Termii USSD is session based. Return the response through the active USSD callback.',

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
