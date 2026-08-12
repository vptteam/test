<?php

declare(strict_types=1);

namespace Services\USSD;

use Core\Logger;
use Throwable;

class UssdService
{
    /**
     * ---------------------------------------------------------
     * USSD SERVICE
     * ---------------------------------------------------------
     *
     * Central USSD service.
     *
     * Responsibilities:
     *
     * - Validate USSD configuration
     * - Resolve active provider
     * - Normalize provider responses
     * - Manage CON / END responses
     * - Enforce session timeout
     * - Provide a common interface for listeners
     *
     * Provider-specific HTTP communication should live in:
     *
     * Services/USSD/Providers/
     *
     * This prevents provider logic from leaking into the
     * listener or bot workflow.
     */

    protected string $provider;

    protected int $sessionTimeout;

    protected bool $enabled;


    public function __construct()
    {
        $this->enabled =
            defined('USSD_ENABLED')
                ? (bool) USSD_ENABLED
                : false;


        $this->provider =
            defined('USSD_PROVIDER')
                ? strtolower(
                    trim(
                        (string) USSD_PROVIDER
                    )
                )
                : '';


        $this->sessionTimeout =
            defined('USSD_SESSION_TIMEOUT')
                ? max(
                    30,
                    (int) USSD_SESSION_TIMEOUT
                )
                : 300;


        Logger::write(
            'ussd_service',
            [
                'step' =>
                    'CONSTRUCTOR',

                'enabled' =>
                    $this->enabled,

                'provider' =>
                    $this->provider,

                'session_timeout' =>
                    $this->sessionTimeout,
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * Check Whether USSD Is Enabled
     * ---------------------------------------------------------
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }


    /**
     * ---------------------------------------------------------
     * Get Active Provider
     * ---------------------------------------------------------
     */
    public function provider(): string
    {
        return $this->provider;
    }


    /**
     * ---------------------------------------------------------
     * Get Session Timeout
     * ---------------------------------------------------------
     */
    public function sessionTimeout(): int
    {
        return $this->sessionTimeout;
    }


    /**
     * ---------------------------------------------------------
     * Validate Configuration
     * ---------------------------------------------------------
     */
    public function validateConfiguration(): array
    {
        try {

            if (!$this->enabled) {

                return [
                    'success' => false,
                    'enabled' => false,
                    'message' =>
                        'USSD service is disabled.',
                ];
            }


            if ($this->provider === '') {

                return [
                    'success' => false,
                    'enabled' => true,
                    'message' =>
                        'No USSD provider configured.',
                ];
            }


            $supported =
                $this->supportedProviders();


            if (
                !in_array(
                    $this->provider,
                    $supported,
                    true
                )
            ) {

                return [
                    'success' => false,
                    'enabled' => true,
                    'provider' =>
                        $this->provider,
                    'message' =>
                        'Unsupported USSD provider.',
                ];
            }


            return [
                'success' => true,
                'enabled' => true,
                'provider' =>
                    $this->provider,
                'message' =>
                    'USSD configuration is valid.',
            ];
        }
        catch (Throwable $e) {

            Logger::write(
                'ussd_service_error',
                [
                    'step' =>
                        'CONFIGURATION_VALIDATION_FAILED',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );


            return [
                'success' => false,
                'message' =>
                    'USSD configuration validation failed.',
            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * Supported Providers
     * ---------------------------------------------------------
     */
    public function supportedProviders(): array
    {
        return [

            'africastalking',

            'termii',

            'twilio',

            'arkesel',

        ];
    }


    /**
     * ---------------------------------------------------------
     * Normalize Incoming Session
     * ---------------------------------------------------------
     */
    public function normalizeSession(
        array $request
    ): array {

        $sessionId =
            trim(
                (string)(
                    $request['session_id']
                    ??
                    $request['sessionId']
                    ??
                    ''
                )
            );


        $phone =
            trim(
                (string)(
                    $request['phone']
                    ??
                    $request['phone_number']
                    ??
                    $request['msisdn']
                    ??
                    ''
                )
            );


        $serviceCode =
            trim(
                (string)(
                    $request['service_code']
                    ??
                    $request['serviceCode']
                    ??
                    ''
                )
            );


        $text =
            trim(
                (string)(
                    $request['text']
                    ??
                    ''
                )
            );


        $network =
            $request['network']
            ??
            null;


        return [

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

            'provider' =>
                $this->provider,

            'started_at' =>
                time(),

        ];
    }


    /**
     * ---------------------------------------------------------
     * Create CON Response
     * ---------------------------------------------------------
     *
     * CON means the USSD session should remain active.
     */
    public function continueSession(
        string $message
    ): string {

        return $this->formatResponse(
            $message,
            true
        );
    }


    /**
     * ---------------------------------------------------------
     * Create END Response
     * ---------------------------------------------------------
     *
     * END terminates the USSD session.
     */
    public function endSession(
        string $message
    ): string {

        return $this->formatResponse(
            $message,
            false
        );
    }


    /**
     * ---------------------------------------------------------
     * Format Provider-Neutral Response
     * ---------------------------------------------------------
     */
    protected function formatResponse(
        string $message,
        bool $continue
    ): string {

        $message =
            trim(
                $message
            );


        if ($message === '') {

            $message =
                $continue
                    ? 'Please continue.'
                    : 'Request completed.';
        }


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


        /*
        |--------------------------------------------------------------------------
        | Maximum USSD Response Length
        |--------------------------------------------------------------------------
        */

        $maxLength =
            500;


        if (
            defined('SMS_MAX_RESPONSE_LENGTH')
            &&
            SMS_MAX_RESPONSE_LENGTH > 0
        ) {

            $maxLength =
                min(
                    500,
                    (int) SMS_MAX_RESPONSE_LENGTH
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
     * Check Session Timeout
     * ---------------------------------------------------------
     */
    public function sessionExpired(
        int $startedAt
    ): bool {

        if ($startedAt <= 0) {

            return true;
        }


        return
            (time() - $startedAt)
            >
            $this->sessionTimeout;
    }


    /**
     * ---------------------------------------------------------
     * Build Session Context
     * ---------------------------------------------------------
     */
    public function buildContext(
        array $request
    ): array {

        $session =
            $this->normalizeSession(
                $request
            );


        return [

            'channel' =>
                'ussd',

            'platform' =>
                'ussd',

            'provider' =>
                $this->provider,

            'session_id' =>
                $session['session_id'],

            'phone' =>
                $session['phone'],

            'service_code' =>
                $session['service_code'],

            'text' =>
                $session['text'],

            'network' =>
                $session['network'],

            'session_timeout' =>
                $this->sessionTimeout,

        ];
    }


    /**
     * ---------------------------------------------------------
     * Health Check
     * ---------------------------------------------------------
     */
    public function health(): array
    {
        $configuration =
            $this->validateConfiguration();


        return [

            'service' =>
                'ussd',

            'enabled' =>
                $this->enabled,

            'provider' =>
                $this->provider,

            'session_timeout' =>
                $this->sessionTimeout,

            'configuration' =>
                $configuration,

        ];
    }
}
?>
