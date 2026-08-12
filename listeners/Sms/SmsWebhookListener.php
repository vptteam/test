<?php

declare(strict_types=1);

namespace Listeners\Sms;

use Core\Logger;
use Services\Sms\SmsService;
use Throwable;

/**
 * --------------------------------------------------------------------------
 * SENDAM SMS WEBHOOK LISTENER
 * --------------------------------------------------------------------------
 *
 * Single incoming SMS webhook entry point.
 *
 * Supported providers:
 *
 * - Twilio
 * - Termii
 * - Africa's Talking
 * - Arkesel
 *
 * The provider is selected through:
 *
 *     SMS_PROVIDER
 *
 * This listener:
 *
 * 1. Receives the provider webhook.
 * 2. Reads the incoming payload.
 * 3. Validates the webhook where applicable.
 * 4. Normalizes the provider payload.
 * 5. Passes the message to the SMS command workflow.
 * 6. Returns a provider-safe response.
 *
 * IMPORTANT:
 *
 * Provider-specific parsing belongs in SmsService.
 * Escrow logic does NOT belong here.
 *
 * --------------------------------------------------------------------------
 */
class SmsWebhookListener
{
    protected SmsService $sms;

    /**
     * ----------------------------------------------------------------------
     * Constructor
     * ----------------------------------------------------------------------
     */
    public function __construct()
    {
        $this->sms = new SmsService();

        Logger::write(
            'sms_webhook_listener',
            [
                'step' => 'CONSTRUCTOR',
                'provider' => $this->sms->provider()
            ]
        );
    }

    /**
     * ----------------------------------------------------------------------
     * Handle Incoming Webhook
     * ----------------------------------------------------------------------
     */
    public function handle(): void
    {
        $rawBody = '';

        try {

            $method =
                strtoupper(
                    (string)(
                        $_SERVER['REQUEST_METHOD']
                        ?? 'GET'
                    )
                );

            $uri =
                (string)(
                    $_SERVER['REQUEST_URI']
                    ?? '/'
                );

            $provider =
                $this->sms->provider();

            $rawBody =
                (string)(
                    file_get_contents('php://input')
                    ?: ''
                );

            Logger::write(
                'sms_webhook_listener',
                [
                    'step' => 'REQUEST_RECEIVED',
                    'method' => $method,
                    'uri' => $uri,
                    'provider' => $provider,
                    'headers' => $this->headers(),
                    'payload' => $rawBody,
                    'post' => $_POST,
                    'get' => $_GET,
                    'time' => date('Y-m-d H:i:s')
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | HTTP Method
            |--------------------------------------------------------------------------
            */

            if (
                $method !== 'POST'
                &&
                $method !== 'GET'
            ) {

                $this->respond(
                    405,
                    [
                        'success' => false,
                        'message' =>
                            'Method not allowed.'
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Webhook Verification
            |--------------------------------------------------------------------------
            |
            | Some providers use GET verification while others send POST
            | messages directly.
            |
            */

            if ($method === 'GET') {

                if (
                    $this->handleVerificationRequest()
                ) {

                    return;
                }

                $this->respond(
                    400,
                    [
                        'success' => false,
                        'message' =>
                            'Invalid webhook verification request.'
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Read Payload
            |--------------------------------------------------------------------------
            */

            $payload =
                $this->buildPayload(
                    $rawBody
                );

            Logger::write(
                'sms_webhook_listener',
                [
                    'step' => 'PAYLOAD_PARSED',
                    'provider' => $provider,
                    'payload' => $payload
                ]
            );

            if (
                $payload === []
            ) {

                $this->respond(
                    400,
                    [
                        'success' => false,
                        'message' =>
                            'Empty webhook payload.'
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Provider Security
            |--------------------------------------------------------------------------
            */

            if (
                !$this->verifyWebhook(
                    $payload,
                    $rawBody
                )
            ) {

                Logger::write(
                    'sms_webhook_listener',
                    [
                        'step' =>
                            'WEBHOOK_AUTHORIZATION_FAILED',
                        'provider' => $provider
                    ]
                );

                $this->respond(
                    401,
                    [
                        'success' => false,
                        'message' =>
                            'Unauthorized webhook.'
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Normalize Provider Payload
            |--------------------------------------------------------------------------
            */

            $normalized =
                $this->sms->normalizeIncoming(
                    $payload,
                    $provider
                );

            Logger::write(
                'sms_webhook_listener',
                [
                    'step' => 'NORMALIZED',
                    'provider' => $provider,
                    'success' =>
                        $normalized['success']
                        ?? false,
                    'phone' =>
                        $normalized['phone']
                        ?? null,
                    'message' =>
                        $normalized['message']
                        ?? null,
                    'message_id' =>
                        $normalized['message_id']
                        ?? null
                ]
            );

            if (
                !($normalized['success'] ?? false)
            ) {

                $this->respond(
                    400,
                    [
                        'success' => false,
                        'message' =>
                            $normalized['message']
                            ??
                            'Invalid SMS payload.'
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Duplicate Protection
            |--------------------------------------------------------------------------
            |
            | Provider message IDs can be used by a persistence layer later.
            | We deliberately do not pretend that a message is idempotent
            | without checking storage.
            |
            */

            $messageId =
                $normalized['message_id']
                ?? null;

            Logger::write(
                'sms_webhook_listener',
                [
                    'step' => 'MESSAGE_ACCEPTED',
                    'provider' => $provider,
                    'phone' =>
                        $normalized['phone'],
                    'message' =>
                        $normalized['message'],
                    'message_id' => $messageId
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Dispatch Into SMS Command Workflow
            |--------------------------------------------------------------------------
            */

            $result =
                $this->dispatchMessage(
                    $normalized
                );

            Logger::write(
                'sms_webhook_listener',
                [
                    'step' => 'COMMAND_DISPATCH_RESULT',
                    'provider' => $provider,
                    'phone' =>
                        $normalized['phone'],
                    'message_id' => $messageId,
                    'result' => $result
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Provider Response
            |--------------------------------------------------------------------------
            |
            | Twilio can consume TwiML.
            | Other providers generally accept a simple HTTP success response.
            |
            */

            if (
                $provider === 'twilio'
            ) {

                $this->respondTwilio(
                    $result
                );

                return;
            }

            $this->respond(
                200,
                [
                    'success' => true,
                    'message' =>
                        'SMS received.'
                ]
            );

        }
        catch (Throwable $e) {

            Logger::write(
                'sms_webhook_error',
                [
                    'step' => 'EXCEPTION',
                    'message' =>
                        $e->getMessage(),
                    'file' =>
                        $e->getFile(),
                    'line' =>
                        $e->getLine(),
                    'trace' =>
                        $e->getTraceAsString(),
                    'payload' =>
                        $rawBody
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Never expose internal exception details
            |--------------------------------------------------------------------------
            */

            $this->respond(
                500,
                [
                    'success' => false,
                    'message' =>
                        'SMS webhook processing failed.'
                ]
            );
        }
    }

    /**
     * ----------------------------------------------------------------------
     * Build Payload
     * ----------------------------------------------------------------------
     *
     * Supports:
     *
     * - JSON
     * - application/x-www-form-urlencoded
     * - multipart/form-data
     *
     * ----------------------------------------------------------------------
     */
    protected function buildPayload(
        string $rawBody
    ): array {

        /*
        |--------------------------------------------------------------------------
        | JSON
        |--------------------------------------------------------------------------
        */

        if (
            trim($rawBody) !== ''
        ) {

            $decoded =
                json_decode(
                    $rawBody,
                    true
                );

            if (
                is_array($decoded)
            ) {

                return $decoded;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Normal POST
        |--------------------------------------------------------------------------
        */

        if (
            !empty($_POST)
        ) {

            return $_POST;
        }

        /*
        |--------------------------------------------------------------------------
        | Query Parameters
        |--------------------------------------------------------------------------
        */

        if (
            !empty($_GET)
        ) {

            return $_GET;
        }

        return [];
    }

    /**
     * ----------------------------------------------------------------------
     * Webhook Verification
     * ----------------------------------------------------------------------
     */
    protected function verifyWebhook(
        array $payload,
        string $rawBody
    ): bool {

        /*
        |--------------------------------------------------------------------------
        | Global security switch
        |--------------------------------------------------------------------------
        */

        if (
            !defined('WEBHOOK_IP_CHECK')
            &&
            !defined('SMS_INCOMING_WEBHOOK_SECRET')
        ) {

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Shared Secret
        |--------------------------------------------------------------------------
        */

        $secret =
            defined('SMS_INCOMING_WEBHOOK_SECRET')
                ? trim(
                    (string)
                    SMS_INCOMING_WEBHOOK_SECRET
                )
                : '';

        if (
            $secret !== ''
        ) {

            $provided =
                $this->webhookSecret(
                    $payload
                );

            /*
            |----------------------------------------------------------------------
            | If provider does not expose the generic secret field,
            | leave provider-specific signature validation to its
            | native signature mechanism.
            |----------------------------------------------------------------------
            */

            if (
                $provided !== ''
                &&
                !hash_equals(
                    $secret,
                    $provided
                )
            ) {

                return false;
            }
        }

        return true;
    }

    /**
     * ----------------------------------------------------------------------
     * Extract Webhook Secret
     * ----------------------------------------------------------------------
     */
    protected function webhookSecret(
        array $payload
    ): string {

        $candidates = [

            'secret',

            'token',

            'webhook_secret',

            'webhookSecret',

            'signature_token'

        ];

        foreach (
            $candidates as $field
        ) {

            if (
                isset($payload[$field])
                &&
                is_scalar(
                    $payload[$field]
                )
            ) {

                return trim(
                    (string)$payload[$field]
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Header based secret
        |--------------------------------------------------------------------------
        */

        $headers =
            $this->headers();

        foreach (
            [
                'X-Webhook-Secret',
                'X-SMS-Webhook-Secret',
                'X-Webhook-Token'
            ] as $header
        ) {

            if (
                isset($headers[$header])
            ) {

                return trim(
                    (string)$headers[$header]
                );
            }
        }

        return '';
    }

    /**
     * ----------------------------------------------------------------------
     * GET Verification
     * ----------------------------------------------------------------------
     */
    protected function handleVerificationRequest(): bool
    {
        $mode =
            $_GET['hub_mode']
            ??
            $_GET['hub.mode']
            ??
            null;

        $token =
            $_GET['hub_verify_token']
            ??
            $_GET['hub.verify_token']
            ??
            null;

        $challenge =
            $_GET['hub_challenge']
            ??
            $_GET['hub.challenge']
            ??
            null;

        /*
        |--------------------------------------------------------------------------
        | No verification parameters
        |--------------------------------------------------------------------------
        */

        if (
            $mode === null
            &&
            $token === null
            &&
            $challenge === null
        ) {

            return false;
        }

        $expected =
            defined('SMS_INCOMING_WEBHOOK_SECRET')
                ? (string)
                    SMS_INCOMING_WEBHOOK_SECRET
                : '';

        if (
            $expected === ''
            ||
            $token === null
            ||
            !hash_equals(
                $expected,
                (string)$token
            )
        ) {

            http_response_code(403);

            echo 'Forbidden';

            return true;
        }

        http_response_code(200);

        echo
            $challenge !== null
                ? (string)$challenge
                : 'OK';

        return true;
    }

    /**
     * ----------------------------------------------------------------------
     * Dispatch SMS Message
     * ----------------------------------------------------------------------
     *
     * This method intentionally keeps the integration point isolated.
     *
     * The actual command engine remains the source of truth.
     *
     * ----------------------------------------------------------------------
     */
    protected function dispatchMessage(
        array $message
    ): array {

        try {

            $phone =
                (string)(
                    $message['phone']
                    ?? ''
                );

            $text =
                trim(
                    (string)(
                        $message['message']
                        ?? ''
                    )
                );

            $provider =
                (string)(
                    $message['provider']
                    ?? $this->sms->provider()
                );

            $messageId =
                $message['message_id']
                ?? null;

            Logger::write(
                'sms_webhook_listener',
                [
                    'step' => 'DISPATCH_MESSAGE',
                    'phone' => $phone,
                    'message' => $text,
                    'provider' => $provider,
                    'message_id' => $messageId
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Existing Bot Command Router
            |--------------------------------------------------------------------------
            |
            | Keep SMS independent from Telegram/WhatsApp transport.
            |
            | If BotCommandRouter exists, it becomes the common command layer.
            |
            */

            if (
                class_exists(
                    \Modules\BotCommandRouter::class
                )
            ) {

                $router =
                    new \Modules\BotCommandRouter();

                /*
                |------------------------------------------------------------------
                | Supported invocation patterns
                |------------------------------------------------------------------
                |
                | Existing projects sometimes expose dispatch(), handle(), or
                | route(). We detect the available method rather than creating
                | another command implementation here.
                |
                */

                if (
                    method_exists(
                        $router,
                        'handle'
                    )
                ) {

                    $result =
                        $router->handle(
                            $phone,
                            $text,
                            [
                                'channel' => 'sms',
                                'provider' => $provider,
                                'message_id' => $messageId,
                                'raw' =>
                                    $message['raw']
                                    ?? []
                            ]
                        );

                    return is_array($result)
                        ? $result
                        : [
                            'success' => true,
                            'result' => $result
                        ];
                }

                if (
                    method_exists(
                        $router,
                        'dispatch'
                    )
                ) {

                    $result =
                        $router->dispatch(
                            $phone,
                            $text,
                            [
                                'channel' => 'sms',
                                'provider' => $provider,
                                'message_id' => $messageId,
                                'raw' =>
                                    $message['raw']
                                    ?? []
                            ]
                        );

                    return is_array($result)
                        ? $result
                        : [
                            'success' => true,
                            'result' => $result
                        ];
                }

                if (
                    method_exists(
                        $router,
                        'route'
                    )
                ) {

                    $result =
                        $router->route(
                            $phone,
                            $text,
                            [
                                'channel' => 'sms',
                                'provider' => $provider,
                                'message_id' => $messageId,
                                'raw' =>
                                    $message['raw']
                                    ?? []
                            ]
                        );

                    return is_array($result)
                        ? $result
                        : [
                            'success' => true,
                            'result' => $result
                        ];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | No Router
            |--------------------------------------------------------------------------
            |
            | Do not execute escrow logic here.
            |
            */

            Logger::write(
                'sms_webhook_listener',
                [
                    'step' =>
                        'COMMAND_ROUTER_NOT_AVAILABLE',
                    'phone' => $phone,
                    'message' => $text
                ]
            );

            return [
                'success' => false,
                'message' =>
                    'SMS command router is not available.'
            ];

        }
        catch (Throwable $e) {

            Logger::write(
                'sms_webhook_error',
                [
                    'step' =>
                        'DISPATCH_MESSAGE_EXCEPTION',
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
                    'Unable to process SMS command.'
            ];
        }
    }

    /**
     * ----------------------------------------------------------------------
     * Twilio Response
     * ----------------------------------------------------------------------
     */
    protected function respondTwilio(
        array $result
    ): void {

        $reply =
            trim(
                (string)(
                    $result['reply']
                    ??
                    $result['message']
                    ??
                    ''
                )
            );

        /*
        |--------------------------------------------------------------------------
        | TwiML XML
        |--------------------------------------------------------------------------
        */

        header(
            'Content-Type: text/xml; charset=UTF-8'
        );

        http_response_code(200);

        if (
            $reply === ''
        ) {

            echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | XML Escape
        |--------------------------------------------------------------------------
        */

        $reply =
            htmlspecialchars(
                $reply,
                ENT_XML1 | ENT_QUOTES,
                'UTF-8'
            );

        echo
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Response>'
            . '<Message>'
            . $reply
            . '</Message>'
            . '</Response>';
    }

    /**
     * ----------------------------------------------------------------------
     * Generic Response
     * ----------------------------------------------------------------------
     */
    protected function respond(
        int $status,
        array $data
    ): void {

        http_response_code(
            $status
        );

        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        echo json_encode(
            $data,
            JSON_UNESCAPED_SLASHES
            |
            JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * ----------------------------------------------------------------------
     * Request Headers
     * ----------------------------------------------------------------------
     */
    protected function headers(): array
    {
        if (
            function_exists('getallheaders')
        ) {

            $headers =
                getallheaders();

            if (
                is_array($headers)
            ) {

                return $headers;
            }
        }

        $headers = [];

        foreach (
            $_SERVER as $key => $value
        ) {

            if (
                str_starts_with(
                    $key,
                    'HTTP_'
                )
            ) {

                $name =
                    str_replace(
                        '_',
                        '-',
                        substr(
                            $key,
                            5
                        )
                    );

                $headers[$name] =
                    $value;
            }
        }

        return $headers;
    }
}
