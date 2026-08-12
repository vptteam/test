<?php

declare(strict_types=1);

namespace Services\USSD\Providers;

/**
 * --------------------------------------------------------------------------
 * SENDAM USSD PROVIDER INTERFACE
 * --------------------------------------------------------------------------
 *
 * Every USSD provider adapter must implement this interface.
 *
 * Providers:
 *
 * - Africa's Talking
 * - Termii
 * - Twilio
 * - Arkesel
 *
 * The purpose of this interface is to keep provider-specific HTTP/API
 * implementation isolated from:
 *
 * - UssdService
 * - UssdListener
 * - BotEngine
 * - WorkflowExecutor
 *
 * --------------------------------------------------------------------------
 */
interface UssdProviderInterface
{
    /**
     * ----------------------------------------------------------------------
     * Provider Name
     * ----------------------------------------------------------------------
     *
     * Returns the internal provider identifier.
     *
     * Example:
     *
     *     africastalking
     *     termii
     *     twilio
     *     arkesel
     */
    public function name(): string;


    /**
     * ----------------------------------------------------------------------
     * Check Configuration
     * ----------------------------------------------------------------------
     *
     * Verifies that the provider has the required configuration values.
     *
     * This should NOT make a live API request.
     *
     * It only checks local configuration such as:
     *
     * - API keys
     * - usernames
     * - sender IDs
     * - service codes
     * - account identifiers
     */
    public function configured(): bool;


    /**
     * ----------------------------------------------------------------------
     * Configuration Details
     * ----------------------------------------------------------------------
     *
     * Returns safe configuration information.
     *
     * IMPORTANT:
     *
     * Never return secret API keys, tokens or passwords in this method.
     */
    public function configuration(): array;


    /**
     * ----------------------------------------------------------------------
     * Normalize Incoming Request
     * ----------------------------------------------------------------------
     *
     * Converts provider-specific webhook data into the common SENDAM
     * USSD structure.
     *
     * Expected structure:
     *
     * [
     *     'session_id'   => '',
     *     'phone'        => '',
     *     'service_code' => '',
     *     'text'         => '',
     *     'network'      => null,
     *     'provider'     => '',
     *     'raw'          => []
     * ]
     */
    public function normalizeRequest(
        array $payload
    ): array;


    /**
     * ----------------------------------------------------------------------
     * Build Response
     * ----------------------------------------------------------------------
     *
     * Creates the provider-compatible response.
     *
     * $message is the actual response text.
     *
     * $continue determines whether the USSD session remains active.
     *
     * Example:
     *
     *     CON Select an option
     *
     * or:
     *
     *     END Transaction completed
     */
    public function response(
        string $message,
        bool $continue = false
    ): string;


    /**
     * ----------------------------------------------------------------------
     * Continue Session
     * ----------------------------------------------------------------------
     *
     * Convenience method for returning a CON response.
     */
    public function continueSession(
        string $message
    ): string;


    /**
     * ----------------------------------------------------------------------
     * End Session
     * ----------------------------------------------------------------------
     *
     * Convenience method for returning an END response.
     */
    public function endSession(
        string $message
    ): string;


    /**
     * ----------------------------------------------------------------------
     * Health Check
     * ----------------------------------------------------------------------
     *
     * Returns provider status information.
     *
     * Example:
     *
     * [
     *     'success'  => true,
     *     'provider' => 'termii',
     *     'configured' => true
     * ]
     */
    public function health(): array;
}
