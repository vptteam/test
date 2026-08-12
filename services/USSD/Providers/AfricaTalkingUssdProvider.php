<?php

declare(strict_types=1);

namespace Services\USSD\Providers;

use Core\Logger;
use Throwable;

class AfricaTalkingUssdProvider implements UssdProviderInterface
{
    /**
     * --------------------------------------------------------------------------
     * CONFIGURATION
     * --------------------------------------------------------------------------
     */

    protected string $username = '';

    protected string $apiKey = '';

    protected string $baseUrl = 'https://api.africastalking.com';

    protected string $serviceCode = '';


    /**
     * --------------------------------------------------------------------------
     * CONSTRUCTOR
     * --------------------------------------------------------------------------
     */

    public function __construct()
    {
        try {

            $this->username =
                defined('AFRICASTALKING_USERNAME')
                    ? trim((string) AFRICASTALKING_USERNAME)
                    : '';

            $this->apiKey =
                defined('AFRICASTALKING_API_KEY')
                    ? trim((string) AFRICASTALKING_API_KEY)
                    : '';

            $this->baseUrl =
                defined('AFRICASTALKING_BASE_URL')
                    ? rtrim(
                        trim((string) AFRICASTALKING_BASE_URL),
                        '/'
                    )
                    : 'https://api.africastalking.com';

            $this->serviceCode =
                defined('USSD_SERVICE_CODE')
                    ? trim((string) USSD_SERVICE_CODE)
                    : '';

            Logger::write(
                'africastalking_ussd_provider',
                [
                    'step' => 'CONSTRUCTOR',

                    'username' =>
                        $this->username,

                    'base_url' =>
                        $this->baseUrl,

                    'service_code' =>
                        $this->serviceCode,

                    'configured' =>
                        $this->configured(),
                ]
            );

        } catch (Throwable $e) {

            Logger::write(
                'africastalking_ussd_provider_error',
                [
                    'step' => 'CONSTRUCTOR_EXCEPTION',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );
        }
    }


    /**
     * --------------------------------------------------------------------------
     * PROVIDER NAME
     * --------------------------------------------------------------------------
     */

    public function name(): string
    {
        return 'africastalking';
    }


    /**
     * --------------------------------------------------------------------------
     * CONFIGURATION CHECK
     * --------------------------------------------------------------------------
     *
     * This only checks local configuration.
     *
     * It does NOT make an API request.
     */

    public function configured(): bool
    {
        return
            $this->username !== ''
            &&
            $this->apiKey !== '';
    }


    /**
     * --------------------------------------------------------------------------
     * SAFE CONFIGURATION DETAILS
     * --------------------------------------------------------------------------
     *
     * Never expose the API key.
     */

    public function configuration(): array
    {
        return [

            'provider' =>
                $this->name(),

            'username' =>
                $this->username,

            'base_url' =>
                $this->baseUrl,

            'service_code' =>
                $this->serviceCode,

            'configured' =>
                $this->configured(),

            'api_key_configured' =>
                $this->apiKey !== '',

        ];
    }


    /**
     * --------------------------------------------------------------------------
     * NORMALIZE REQUEST
     * --------------------------------------------------------------------------
     *
     * This is the method required by UssdProviderInterface.
     */

    public function normalizeRequest(
        array $payload
    ): array {

        try {

            Logger::write(
                'africastalking_ussd_provider',
                [
                    'step' => 'NORMALIZE_REQUEST',

                    'payload' =>
                        $payload,
                ]
            );


            $phone =
                $payload['phoneNumber']
                ??
                $payload['phone']
                ??
                $payload['msisdn']
                ??
                '';


            $sessionId =
                $payload['sessionId']
                ??
                $payload['session_id']
                ??
                '';


            $serviceCode =
                $payload['serviceCode']
                ??
                $payload['service_code']
                ??
                $this->serviceCode
                ??
                '';


            $text =
                $payload['text']
                ??
                '';


            $networkCode =
                $payload['networkCode']
                ??
                $payload['network_code']
                ??
                null;


            $network =
                $payload['network']
                ??
                null;


            $phone =
                $this->normalizePhone(
                    (string) $phone
                );


            $sessionId =
                trim(
                    (string) $sessionId
                );


            $serviceCode =
                trim(
                    (string) $serviceCode
                );


            $text =
                trim(
                    (string) $text
                );


            $result = [

                'provider' =>
                    $this->name(),

                'platform' =>
                    'ussd',

                'session_id' =>
                    $sessionId,

                'phone' =>
                    $phone,

                'service_code' =>
                    $serviceCode,

                'text' =>
                    $text,

                'network' =>
                    $network,

                'network_code' =>
                    $networkCode,

                'request_type' =>
                    'ussd',

                'raw' =>
                    $payload,

            ];


            if (
                $phone === ''
                ||
                $sessionId === ''
            ) {

                $result['error'] = true;

                $result['error_message'] =
                    'Missing phone number or session ID.';
            }


            Logger::write(
                'africastalking_ussd_provider',
                [
                    'step' => 'REQUEST_NORMALIZED',

                    'result' =>
                        $result,
                ]
            );


            return $result;

        } catch (Throwable $e) {

            Logger::write(
                'africastalking_ussd_provider_error',
                [
                    'step' =>
                        'NORMALIZE_REQUEST_FAILED',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),

                    'trace' =>
                        $e->getTraceAsString(),
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

                'network_code' =>
                    null,

                'request_type' =>
                    'ussd',

                'raw' =>
                    $payload,

                'error' =>
                    true,

                'error_message' =>
                    'Unable to normalize USSD request.',

            ];
        }
    }


    /**
     * --------------------------------------------------------------------------
     * BACKWARD-COMPATIBILITY ALIAS
     * --------------------------------------------------------------------------
     *
     * UssdListener currently calls normalizeIncoming().
     *
     * Keep this method so the existing listener continues working.
     */

    public function normalizeIncoming(
        array $payload
    ): array {

        return $this->normalizeRequest(
            $payload
        );
    }


    /**
     * --------------------------------------------------------------------------
     * BUILD RESPONSE
     * --------------------------------------------------------------------------
     *
     * Required by UssdProviderInterface.
     */

    public function response(
        string $message,
        bool $continue = false
    ): string {

        $message =
            $this->cleanResponseMessage(
                $message
            );


        if ($message === '') {

            $message =
                $continue
                    ? 'Please continue.'
                    : 'Request completed.';
        }


        $message =
            $this->limitResponseLength(
                $message
            );


        $response =
            $continue
                ? 'CON ' . $message
                : 'END ' . $message;


        Logger::write(
            'africastalking_ussd_provider',
            [
                'step' =>
                    'RESPONSE_BUILT',

                'continue' =>
                    $continue,

                'response' =>
                    $response,
            ]
        );


        return $response;
    }


    /**
     * --------------------------------------------------------------------------
     * BACKWARD-COMPATIBILITY ALIAS
     * --------------------------------------------------------------------------
     *
     * UssdListener currently calls formatResponse().
     */

    public function formatResponse(
        string $message,
        bool $continue = false
    ): string {

        return $this->response(
            $message,
            $continue
        );
    }


    /**
     * --------------------------------------------------------------------------
     * CONTINUE SESSION
     * --------------------------------------------------------------------------
     */

    public function continueSession(
        string $message
    ): string {

        return $this->response(
            $message,
            true
        );
    }


    /**
     * --------------------------------------------------------------------------
     * END SESSION
     * --------------------------------------------------------------------------
     */

    public function endSession(
        string $message
    ): string {

        return $this->response(
            $message,
            false
        );
    }


    /**
     * --------------------------------------------------------------------------
     * VALIDATE WEBHOOK
     * --------------------------------------------------------------------------
     *
     * This is an optional application-level security layer.
     *
     * Africa's Talking does not require our own custom secret here unless
     * we configure one.
     */

    public function validateWebhook(
        array $headers = [],
        ?string $rawBody = null
    ): bool {

        try {

            $configuredSecret =
                defined('USSD_WEBHOOK_SECRET')
                    ? trim(
                        (string) USSD_WEBHOOK_SECRET
                    )
                    : '';


            /*
             * No application secret configured.
             *
             * Do not reject the request.
             */

            if ($configuredSecret === '') {

                Logger::write(
                    'africastalking_ussd_provider',
                    [
                        'step' =>
                            'WEBHOOK_VALIDATION_SKIPPED',

                        'reason' =>
                            'NO_SECRET_CONFIGURED',
                    ]
                );

                return true;
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


            if (
                !is_string($receivedSecret)
                ||
                trim($receivedSecret) === ''
            ) {

                Logger::write(
                    'africastalking_ussd_provider_error',
                    [
                        'step' =>
                            'WEBHOOK_SECRET_MISSING',
                    ]
                );

                return false;
            }


            $valid =
                hash_equals(
                    $configuredSecret,
                    trim($receivedSecret)
                );


            Logger::write(
                'africastalking_ussd_provider',
                [
                    'step' =>
                        'WEBHOOK_VALIDATION',

                    'valid' =>
                        $valid,
                ]
            );


            return $valid;

        } catch (Throwable $e) {

            Logger::write(
                'africastalking_ussd_provider_error',
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
     * --------------------------------------------------------------------------
     * SEND
     * --------------------------------------------------------------------------
     *
     * Africa's Talking USSD is callback/session driven.
     *
     * We do not initiate a USSD session from here.
     *
     * The response is returned directly from the webhook.
     */

    public function send(
        string $phone,
        string $message,
        array $context = []
    ): array {

        Logger::write(
            'africastalking_ussd_provider',
            [
                'step' =>
                    'SEND_REQUEST',

                'phone' =>
                    $phone,

                'message' =>
                    $message,

                'context' =>
                    $context,

                'configured' =>
                    $this->configured(),
            ]
        );


        return [

            'success' =>
                false,

            'provider' =>
                $this->name(),

            'message' =>
                "Africa's Talking USSD is session callback based. Return the response through the active USSD request.",

            'configured' =>
                $this->configured(),

        ];
    }


    /**
     * --------------------------------------------------------------------------
     * HEALTH
     * --------------------------------------------------------------------------
     */

    public function health(): array
    {
        return [

            'success' =>
                true,

            'provider' =>
                $this->name(),

            'configured' =>
                $this->configured(),

            'api_key_configured' =>
                $this->apiKey !== '',

            'username_configured' =>
                $this->username !== '',

            'base_url' =>
                $this->baseUrl,

            'service_code' =>
                $this->serviceCode,

        ];
    }


    /**
     * --------------------------------------------------------------------------
     * CLEAN RESPONSE
     * --------------------------------------------------------------------------
     */

    protected function cleanResponseMessage(
        string $message
    ): string {

        $message =
            trim(
                $message
            );


        /*
         * Remove an existing CON or END prefix.
         */

        $message =
            preg_replace(
                '/^(CON|END)\s+/i',
                '',
                $message
            )
            ??
            $message;


        /*
         * Remove excessive whitespace.
         */

        $message =
            preg_replace(
                '/[ \t]+/',
                ' ',
                $message
            )
            ??
            $message;


        return trim(
            $message
        );
    }


    /**
     * --------------------------------------------------------------------------
     * RESPONSE LENGTH
     * --------------------------------------------------------------------------
     */

    protected function limitResponseLength(
        string $message
    ): string {

        $maxLength = 500;


        if (
            defined('USSD_MAX_RESPONSE_LENGTH')
            &&
            (int) USSD_MAX_RESPONSE_LENGTH > 0
        ) {

            $maxLength =
                min(
                    500,
                    max(
                        50,
                        (int) USSD_MAX_RESPONSE_LENGTH
                    )
                );
        }


        if (
            strlen($message) > $maxLength
        ) {

            $message =
                substr(
                    $message,
                    0,
                    $maxLength
                );
        }


        return $message;
    }


    /**
     * --------------------------------------------------------------------------
     * NORMALIZE PHONE
     * --------------------------------------------------------------------------
     */

    protected function normalizePhone(
        string $phone
    ): string {

        $phone =
            trim(
                $phone
            );


        /*
         * Keep digits and a possible leading +.
         */

        $phone =
            preg_replace(
                '/[^0-9+]/',
                '',
                $phone
            )
            ??
            '';


        /*
         * Remove leading +.
         */

        $phone =
            ltrim(
                $phone,
                '+'
            );


        /*
         * Nigerian local number:
         *
         * 08012345678
         *
         * becomes:
         *
         * 2348012345678
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
