<?php

declare(strict_types=1);

namespace Listeners\Sms;

use Core\Logger;
use Models\User;
use Modules\BotEngine;
use Throwable;

/**
 * --------------------------------------------------------------------------
 * SENDAM / PINGCHECKOUT
 * SMS LISTENER
 * --------------------------------------------------------------------------
 *
 * File:
 *
 *     listeners/Sms/SMSListener.php
 *
 * Namespace:
 *
 *     Listeners\Sms
 *
 * Class:
 *
 *     SMSListener
 *
 * Supported providers:
 *
 *     - Twilio
 *     - Termii
 *     - Africa's Talking
 *     - Arkesel
 *
 * --------------------------------------------------------------------------
 */
class SmsListener
{
    /**
     * ----------------------------------------------------------------------
     * Handle Incoming SMS Webhook
     * ----------------------------------------------------------------------
     */
    public function handle(): void
    {
        $rawBody = '';

        try {

            $rawBody = (string) file_get_contents('php://input');

            Logger::write(
                'sms_listener',
                [
                    'step' => 'REQUEST_RECEIVED',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                    'uri' => $_SERVER['REQUEST_URI'] ?? null,
                    'provider' => defined('SMS_PROVIDER')
                        ? SMS_PROVIDER
                        : null,
                    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
                    'post' => $_POST,
                    'get' => $_GET,
                    'raw' => $rawBody,
                    'time' => date('Y-m-d H:i:s'),
                ]
            );

            /*
             * SMS providers normally send POST requests.
             *
             * We deliberately return 200 for non-POST requests so that
             * provider verification/health checks do not produce noisy
             * failures.
             */
            $method = strtoupper(
                (string) ($_SERVER['REQUEST_METHOD'] ?? '')
            );

            if ($method !== 'POST') {

                http_response_code(200);

                echo 'OK';

                return;
            }

            /*
             * Resolve configured SMS provider.
             */
            $provider = defined('SMS_PROVIDER')
                ? strtolower(
                    trim(
                        (string) SMS_PROVIDER
                    )
                )
                : '';

            if ($provider === '') {

                Logger::write(
                    'sms_listener_error',
                    [
                        'step' => 'PROVIDER_NOT_CONFIGURED',
                    ]
                );

                http_response_code(500);

                echo 'SMS provider not configured';

                return;
            }

            Logger::write(
                'sms_listener',
                [
                    'step' => 'PROVIDER_RESOLVED',
                    'provider' => $provider,
                ]
            );

            /*
             * Normalize provider payload.
             */
            $message = $this->normalizePayload(
                $provider,
                $rawBody
            );

            if ($message === null) {

                Logger::write(
                    'sms_listener',
                    [
                        'step' => 'MESSAGE_NOT_RECOGNIZED',
                        'provider' => $provider,
                        'post' => $_POST,
                        'raw' => $rawBody,
                    ]
                );

                /*
                 * Return 200 to prevent provider retry loops.
                 */
                http_response_code(200);

                echo 'OK';

                return;
            }

            Logger::write(
                'sms_listener',
                [
                    'step' => 'MESSAGE_NORMALIZED',
                    'provider' => $provider,
                    'message' => $message,
                ]
            );

            /*
             * Send normalized message into the existing bot engine.
             */
            $this->processMessage(
                $message
            );

            http_response_code(200);

            echo 'OK';

        } catch (Throwable $e) {

            Logger::write(
                'sms_listener_error',
                [
                    'step' => 'LISTENER_EXCEPTION',
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            /*
             * Never expose internal application errors to the SMS provider.
             */
            http_response_code(200);

            echo 'OK';
        }
    }


    /**
     * ----------------------------------------------------------------------
     * Normalize Provider Payload
     * ----------------------------------------------------------------------
     */
    protected function normalizePayload(
        string $provider,
        string $rawBody = ''
    ): ?array {

        try {

            return match ($provider) {

                'twilio' =>
                    $this->normalizeTwilio(),

                'termii' =>
                    $this->normalizeTermii(
                        $rawBody
                    ),

                'africastalking',
                'africa_talking',
                'africas_talking' =>
                    $this->normalizeAfricaTalking(),

                'arkesel' =>
                    $this->normalizeArkesel(),

                default =>
                    $this->normalizeGeneric(
                        $rawBody
                    ),
            };

        } catch (Throwable $e) {

            Logger::write(
                'sms_listener_error',
                [
                    'step' => 'NORMALIZATION_EXCEPTION',
                    'provider' => $provider,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );

            return null;
        }
    }


    /**
     * ----------------------------------------------------------------------
     * Twilio
     * ----------------------------------------------------------------------
     *
     * Typical Twilio SMS payload:
     *
     *     From
     *     Body
     *     MessageSid
     */
    protected function normalizeTwilio(): ?array
    {
        $from = trim(
            (string) (
                $_POST['From']
                ??
                $_POST['from']
                ??
                ''
            )
        );

        $text = trim(
            (string) (
                $_POST['Body']
                ??
                $_POST['body']
                ??
                $_POST['message']
                ??
                ''
            )
        );

        $messageId =
            $_POST['MessageSid']
            ??
            $_POST['message_sid']
            ??
            $_POST['messageId']
            ??
            null;

        if ($from === '' || $text === '') {
            return null;
        }

        return $this->buildMessage(
            $from,
            $text,
            $messageId,
            'twilio'
        );
    }


    /**
     * ----------------------------------------------------------------------
     * Termii
     * ----------------------------------------------------------------------
     */
    protected function normalizeTermii(
        string $rawBody = ''
    ): ?array {

        $json = $this->decodeJsonBody(
            $rawBody
        );

        $data = array_merge(
            $json,
            $_POST
        );

        $from = trim(
            (string) (
                $data['from']
                ??
                $data['sender']
                ??
                $data['sender_id']
                ??
                $data['phone']
                ??
                $data['msisdn']
                ??
                $data['phoneNumber']
                ??
                ''
            )
        );

        $text = trim(
            (string) (
                $data['message']
                ??
                $data['text']
                ??
                $data['body']
                ??
                ''
            )
        );

        $messageId =
            $data['message_id']
            ??
            $data['messageId']
            ??
            $data['id']
            ??
            null;

        if ($from === '' || $text === '') {
            return null;
        }

        return $this->buildMessage(
            $from,
            $text,
            $messageId,
            'termii'
        );
    }


    /**
     * ----------------------------------------------------------------------
     * Africa's Talking
     * ----------------------------------------------------------------------
     */
    protected function normalizeAfricaTalking(): ?array
    {
        $from = trim(
            (string) (
                $_POST['from']
                ??
                $_POST['phoneNumber']
                ??
                $_POST['phone']
                ??
                ''
            )
        );

        $text = trim(
            (string) (
                $_POST['text']
                ??
                $_POST['message']
                ??
                $_POST['body']
                ??
                ''
            )
        );

        $messageId =
            $_POST['id']
            ??
            $_POST['messageId']
            ??
            $_POST['message_id']
            ??
            null;

        if ($from === '' || $text === '') {
            return null;
        }

        return $this->buildMessage(
            $from,
            $text,
            $messageId,
            'africastalking'
        );
    }


    /**
     * ----------------------------------------------------------------------
     * Arkesel
     * ----------------------------------------------------------------------
     */
    protected function normalizeArkesel(): ?array
    {
        $from = trim(
            (string) (
                $_POST['from']
                ??
                $_POST['sender']
                ??
                $_POST['phone']
                ??
                $_POST['mobile']
                ??
                $_POST['msisdn']
                ??
                ''
            )
        );

        $text = trim(
            (string) (
                $_POST['message']
                ??
                $_POST['text']
                ??
                $_POST['body']
                ??
                ''
            )
        );

        $messageId =
            $_POST['message_id']
            ??
            $_POST['messageId']
            ??
            $_POST['id']
            ??
            null;

        if ($from === '' || $text === '') {
            return null;
        }

        return $this->buildMessage(
            $from,
            $text,
            $messageId,
            'arkesel'
        );
    }


    /**
     * ----------------------------------------------------------------------
     * Generic Provider
     * ----------------------------------------------------------------------
     */
    protected function normalizeGeneric(
        string $rawBody = ''
    ): ?array {

        $json = $this->decodeJsonBody(
            $rawBody
        );

        $data = array_merge(
            $json,
            $_POST
        );

        $from = trim(
            (string) (
                $data['from']
                ??
                $data['From']
                ??
                $data['phone']
                ??
                $data['mobile']
                ??
                $data['msisdn']
                ??
                $data['phoneNumber']
                ??
                ''
            )
        );

        $text = trim(
            (string) (
                $data['text']
                ??
                $data['message']
                ??
                $data['Body']
                ??
                $data['body']
                ??
                ''
            )
        );

        $messageId =
            $data['id']
            ??
            $data['message_id']
            ??
            $data['messageId']
            ??
            $data['MessageSid']
            ??
            null;

        if ($from === '' || $text === '') {
            return null;
        }

        return $this->buildMessage(
            $from,
            $text,
            $messageId,
            'sms'
        );
    }


    /**
     * ----------------------------------------------------------------------
     * Decode JSON Request
     * ----------------------------------------------------------------------
     */
    protected function decodeJsonBody(
        string $rawBody
    ): array {

        if (trim($rawBody) === '') {
            return [];
        }

        $decoded = json_decode(
            $rawBody,
            true
        );

        return is_array($decoded)
            ? $decoded
            : [];
    }


    /**
     * ----------------------------------------------------------------------
     * Build Internal SMS Message
     * ----------------------------------------------------------------------
     */
    protected function buildMessage(
        string $from,
        string $text,
        mixed $messageId,
        string $provider
    ): array {

        $phone = $this->normalizePhone(
            $from
        );

        return [
            'platform' => 'sms',

            'provider' => $provider,

            'phone' => $phone,

            'platform_id' => $phone,

            'name' => '',

            'type' => 'text',

            'text' => trim($text),

            'message_id' =>
                $messageId !== null
                    ? (string) $messageId
                    : null,

            'media' => null,

            'raw' => $_POST,
        ];
    }


    /**
     * ----------------------------------------------------------------------
     * Process Message Through BotEngine
     * ----------------------------------------------------------------------
     */
    protected function processMessage(
        array $message
    ): void {

        Logger::write(
            'sms_message_processing',
            [
                'step' => 'PROCESS_START',
                'provider' =>
                    $message['provider'] ?? null,
                'phone' =>
                    $message['phone'] ?? null,
                'text' =>
                    $message['text'] ?? null,
                'message_id' =>
                    $message['message_id'] ?? null,
            ]
        );

        /*
         * Resolve or create internal user.
         */
        $userModel = new User();

        $dbUser = $userModel->findOrCreatePlatformUser(
            'sms',
            (string) (
                $message['platform_id']
                ?? ''
            ),
            $message['phone']
                ?? null,
            $message['name']
                ?? null
        );

        if (
            !is_array($dbUser)
            ||
            empty($dbUser['id'])
        ) {

            Logger::write(
                'sms_listener_error',
                [
                    'step' => 'USER_RESOLUTION_FAILED',
                    'phone' =>
                        $message['phone']
                        ?? null,
                    'provider' =>
                        $message['provider']
                        ?? null,
                ]
            );

            return;
        }

        /*
         * Build internal user representation.
         */
        $user = [
            'id' => (int) $dbUser['id'],

            'platform' => 'sms',

            'platform_id' =>
                (string) (
                    $message['platform_id']
                    ?? ''
                ),

            'phone' =>
                $message['phone']
                ?? null,

            'name' =>
                $dbUser['name']
                ??
                $message['name']
                ??
                '',
        ];

        /*
         * Build internal bot message.
         */
        $internalMessage = [
            'platform' => 'sms',

            'provider' =>
                $message['provider']
                ?? null,

            'phone' =>
                $message['phone']
                ?? null,

            'type' =>
                $message['type']
                ?? 'text',

            'text' =>
                $message['text']
                ?? '',

            'message_id' =>
                $message['message_id']
                ?? null,

            'media' =>
                $message['media']
                ?? null,

            'raw' =>
                $message['raw']
                ?? $_POST,
        ];

        Logger::write(
            'before_sms_bot_engine',
            [
                'user' => $user,
                'message' => $internalMessage,
            ]
        );

        try {

            $bot = new BotEngine();

            $bot->process(
                $user,
                $internalMessage
            );

            Logger::write(
                'after_sms_bot_engine',
                [
                    'step' => 'PROCESS_COMPLETED',
                    'status' => 'completed',
                    'provider' =>
                        $message['provider']
                        ?? null,
                    'user_id' =>
                        $user['id'],
                ]
            );

        } catch (Throwable $e) {

            Logger::write(
                'sms_bot_engine_error',
                [
                    'step' => 'BOT_ENGINE_EXCEPTION',
                    'provider' =>
                        $message['provider']
                        ?? null,
                    'user_id' =>
                        $user['id']
                        ?? null,
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
        }
    }


    /**
     * ----------------------------------------------------------------------
     * Normalize Phone Number
     * ----------------------------------------------------------------------
     */
    protected function normalizePhone(
        string $phone
    ): string {

        $phone = trim(
            $phone
        );

        /*
         * Remove provider prefixes.
         */
        $phone = preg_replace(
            '/^(sms|whatsapp):/i',
            '',
            $phone
        ) ?? $phone;

        /*
         * Remove spaces, brackets, dashes and other formatting.
         */
        $phone = preg_replace(
            '/[^0-9+]/',
            '',
            $phone
        ) ?? '';

        /*
         * Remove leading plus.
         */
        $phone = ltrim(
            $phone,
            '+'
        );

        /*
         * Convert Nigerian local numbers:
         *
         * 08012345678
         *
         * to:
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