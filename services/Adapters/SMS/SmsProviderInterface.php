<?php

declare(strict_types=1);

namespace Services\Adapters\SMS;

interface SmsProviderInterface
{
    /**
     * ---------------------------------------------------------
     * Send SMS
     * ---------------------------------------------------------
     */
    public function send(
        string $to,
        string $message
    ): array;


    /**
     * ---------------------------------------------------------
     * Normalize Incoming SMS
     * ---------------------------------------------------------
     *
     * Converts the provider-specific webhook payload into:
     *
     * [
     *     'success'     => true,
     *     'phone'      => '2348012345678',
     *     'message'    => 'VERIFY SDM-000033',
     *     'message_id' => '...',
     *     'provider'   => 'twilio',
     *     'raw'        => [...]
     * ]
     */
    public function incoming(
        array $request
    ): array;


    /**
     * ---------------------------------------------------------
     * Verify Webhook
     * ---------------------------------------------------------
     *
     * Providers that support webhook signatures should verify
     * them here.
     *
     * Providers without signature support may return true after
     * performing whatever validation their API requires.
     */
    public function verifyWebhook(
        array $request,
        array $server = []
    ): bool;


    /**
     * ---------------------------------------------------------
     * Provider Name
     * ---------------------------------------------------------
     */
    public function name(): string;


    /**
     * ---------------------------------------------------------
     * Health Check
     * ---------------------------------------------------------
     */
    public function health(): array;
}
