<?php

declare(strict_types=1);

namespace Listeners\Web;

use Controllers\EscrowApiController;
use Core\Logger;
use Models\User;
use Modules\BotEngine;
use Throwable;


/**
 * --------------------------------------------------------------------------
 * SENDAM / PINGCHECKOUT UNIVERSAL WEB LISTENER
 * --------------------------------------------------------------------------
 *
 * Generic HTTP gateway for external applications.
 *
 * Supported:
 *
 *     POST /web
 *     POST /api/web
 *
 * Existing escrow API endpoints remain supported:
 *
 *     POST /api/escrow/verify
 *     POST /api/escrow/release
 *     POST /api/escrow/payment
 *     POST /api/escrow/payment/status
 *
 * The listener does NOT contain business logic.
 *
 * Its responsibilities are:
 *
 *     1. Receive HTTP request
 *     2. Authenticate external application
 *     3. Read request payload
 *     4. Normalize external message
 *     5. Resolve internal user
 *     6. Pass message to BotEngine
 *     7. Return normalized JSON response
 *
 * External applications can therefore use the same BotEngine used by:
 *
 *     Telegram
 *     WhatsApp
 *     SMS
 *     USSD
 *     Website
 *     Mobile applications
 *     Other approved integrations
 *
 * --------------------------------------------------------------------------
 */
class WebListener
{
    /**
     * ----------------------------------------------------------------------
     * Handle HTTP request
     * ----------------------------------------------------------------------
     */
    public function handle(): void
    {
        $requestId =
            $this->requestId();


        try {

            Logger::write(
                'web_listener',
                [
                    'step' =>
                        'REQUEST_RECEIVED',

                    'request_id' =>
                        $requestId,

                    'method' =>
                        $_SERVER['REQUEST_METHOD']
                        ?? null,

                    'uri' =>
                        $_SERVER['REQUEST_URI']
                        ?? null,

                    'ip' =>
                        $_SERVER['REMOTE_ADDR']
                        ?? null,

                    'user_agent' =>
                        $_SERVER['HTTP_USER_AGENT']
                        ?? null,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Request Method
            |--------------------------------------------------------------------------
            */

            $method =
                strtoupper(
                    $_SERVER['REQUEST_METHOD']
                    ?? 'GET'
                );


            if (
                $method !== 'POST'
            ) {

                $this->json(
                    [
                        'success' =>
                            false,

                        'message' =>
                            'Method not allowed.',

                        'request_id' =>
                            $requestId,
                    ],
                    405
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | API Authentication
            |--------------------------------------------------------------------------
            */

            if (
                !$this->authorize()
            ) {

                Logger::write(
                    'web_listener',
                    [
                        'step' =>
                            'AUTHORIZATION_FAILED',

                        'request_id' =>
                            $requestId,

                        'ip' =>
                            $_SERVER['REMOTE_ADDR']
                            ?? null,
                    ]
                );


                $this->json(
                    [
                        'success' =>
                            false,

                        'message' =>
                            'Unauthorized request.',

                        'request_id' =>
                            $requestId,
                    ],
                    401
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Read Payload
            |--------------------------------------------------------------------------
            */

            $payload =
                $this->payload();


            if (
                empty($payload)
            ) {

                Logger::write(
                    'web_listener_error',
                    [
                        'step' =>
                            'EMPTY_PAYLOAD',

                        'request_id' =>
                            $requestId,
                    ]
                );


                $this->json(
                    [
                        'success' =>
                            false,

                        'message' =>
                            'Request payload is empty.',

                        'request_id' =>
                            $requestId,
                    ],
                    400
                );

                return;
            }


            Logger::write(
                'web_listener',
                [
                    'step' =>
                        'PAYLOAD_RECEIVED',

                    'request_id' =>
                        $requestId,

                    'keys' =>
                        array_keys(
                            $payload
                        ),
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Determine Endpoint
            |--------------------------------------------------------------------------
            */

            $path =
                parse_url(
                    $_SERVER['REQUEST_URI']
                    ?? '/',
                    PHP_URL_PATH
                );


            if (
                !is_string($path)
                ||
                $path === ''
            ) {

                $path = '/';
            }


            if (
                $path !== '/'
            ) {

                $path =
                    rtrim(
                        $path,
                        '/'
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | Universal Web Message
            |--------------------------------------------------------------------------
            */

            if (
                $path === '/web'
                ||
                $path === '/api/web'
            ) {

                $this->handleMessage(
                    $payload,
                    $requestId
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Existing Escrow API
            |--------------------------------------------------------------------------
            */

            if (
                str_starts_with(
                    $path,
                    '/api/escrow/'
                )
            ) {

                $this->handleEscrowApi(
                    $path,
                    $payload,
                    $requestId
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Endpoint Not Found
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'web_listener',
                [
                    'step' =>
                        'ENDPOINT_NOT_FOUND',

                    'request_id' =>
                        $requestId,

                    'path' =>
                        $path,
                ]
            );


            $this->json(
                [
                    'success' =>
                        false,

                    'message' =>
                        'API endpoint not found.',

                    'request_id' =>
                        $requestId,
                ],
                404
            );
        }
        catch (Throwable $e) {

            Logger::write(
                'web_listener_error',
                [
                    'step' =>
                        'EXCEPTION',

                    'request_id' =>
                        $requestId,

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


            $this->json(
                [
                    'success' =>
                        false,

                    'message' =>
                        'Unable to process request.',

                    'request_id' =>
                        $requestId,
                ],
                500
            );
        }
    }


    /**
     * ----------------------------------------------------------------------
     * Handle Universal Web Message
     * ----------------------------------------------------------------------
     */
    protected function handleMessage(
        array $payload,
        string $requestId
    ): void {

        try {

            /*
            |--------------------------------------------------------------------------
            | Normalize External Message
            |--------------------------------------------------------------------------
            */

            $message =
                $this->normalizeMessage(
                    $payload
                );


            if (
                !$message
            ) {

                Logger::write(
                    'web_listener_error',
                    [
                        'step' =>
                            'MESSAGE_NORMALIZATION_FAILED',

                        'request_id' =>
                            $requestId,

                        'payload_keys' =>
                            array_keys(
                                $payload
                            ),
                    ]
                );


                $this->json(
                    [
                        'success' =>
                            false,

                        'message' =>
                            'Invalid message payload.',

                        'request_id' =>
                            $requestId,
                    ],
                    400
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Resolve User
            |--------------------------------------------------------------------------
            */

            $user =
                $this->resolveUser(
                    $message
                );


            if (
                !$user
            ) {

                Logger::write(
                    'web_listener_error',
                    [
                        'step' =>
                            'USER_RESOLUTION_FAILED',

                        'request_id' =>
                            $requestId,

                        'platform' =>
                            $message['platform']
                            ?? null,

                        'platform_id' =>
                            $message['platform_id']
                            ?? null,
                    ]
                );


                $this->json(
                    [
                        'success' =>
                            false,

                        'message' =>
                            'Unable to identify user.',

                        'request_id' =>
                            $requestId,
                    ],
                    422
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Process Through BotEngine
            |--------------------------------------------------------------------------
            */

            $bot =
                new BotEngine();


            Logger::write(
                'before_web_bot_engine',
                [
                    'request_id' =>
                        $requestId,

                    'user' =>
                        $user,

                    'message' =>
                        $message,
                ]
            );


            $result =
                $bot->process(
                    $user,
                    $message
                );


            Logger::write(
                'after_web_bot_engine',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $user['id']
                        ?? null,

                    'result' =>
                        $result,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Normalize BotEngine Result
            |--------------------------------------------------------------------------
            */

            $response =
                $this->normalizeBotResult(
                    $result
                );


            $response['request_id'] =
                $requestId;


            $response['user_id'] =
                $user['id']
                ?? null;


            $this->json(
                $response,
                !empty(
                    $response['success']
                )
                    ? 200
                    : 400
            );
        }
        catch (Throwable $e) {

            Logger::write(
                'web_listener_error',
                [
                    'step' =>
                        'MESSAGE_PROCESSING_EXCEPTION',

                    'request_id' =>
                        $requestId,

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


            $this->json(
                [
                    'success' =>
                        false,

                    'message' =>
                        'Unable to process message.',

                    'request_id' =>
                        $requestId,
                ],
                500
            );
        }
    }


    /**
     * ----------------------------------------------------------------------
     * Normalize External Message
     * ----------------------------------------------------------------------
     *
     * Accepted examples:
     *
     * {
     *     "message": "VERIFY SDM-000001",
     *     "platform": "web",
     *     "user_id": "123"
     * }
     *
     * {
     *     "text": "VERIFY SDM-000001",
     *     "phone": "08012345678"
     * }
     *
     * {
     *     "message": "HELLO",
     *     "platform_id": "customer-100"
     * }
     */
    protected function normalizeMessage(
        array $payload
    ): ?array {

        $text =
            $payload['text']
            ??
            $payload['message']
            ??
            $payload['body']
            ??
            $payload['command']
            ??
            '';


        $text =
            trim(
                (string)$text
            );


        if (
            $text === ''
        ) {

            return null;
        }


        $platform =
            trim(
                (string)(
                    $payload['platform']
                    ??
                    'web'
                )
            );


        if (
            $platform === ''
        ) {

            $platform = 'web';
        }


        $platformId =
            $payload['platform_id']
            ??
            $payload['user_id']
            ??
            $payload['external_user_id']
            ??
            $payload['customer_id']
            ??
            $payload['phone']
            ??
            null;


        if (
            $platformId === null
            ||
            trim(
                (string)$platformId
            ) === ''
        ) {

            return null;
        }


        $phone =
            $payload['phone']
            ??
            $payload['phone_number']
            ??
            $payload['mobile']
            ??
            null;


        if (
            $phone !== null
        ) {

            $phone =
                $this->normalizePhone(
                    (string)$phone
                );
        }


        return [

            'platform' =>
                $platform,

            'provider' =>
                $payload['provider']
                ?? 'web',

            'platform_id' =>
                (string)$platformId,

            'phone' =>
                $phone,

            'type' =>
                $payload['type']
                ?? 'text',

            'text' =>
                $text,

            'session_id' =>
                $payload['session_id']
                ??
                $payload['sessionId']
                ??
                null,

            'raw' =>
                $payload,

        ];
    }


    /**
     * ----------------------------------------------------------------------
     * Resolve User
     * ----------------------------------------------------------------------
     */
    protected function resolveUser(
        array $message
    ): ?array {

        try {

            $userModel =
                new User();


            $platform =
                (string)(
                    $message['platform']
                    ?? 'web'
                );


            $platformId =
                (string)(
                    $message['platform_id']
                    ?? ''
                );


            $phone =
                $message['phone']
                ?? null;


            if (
                $platformId === ''
            ) {

                return null;
            }


            $dbUser =
                $userModel->findOrCreatePlatformUser(
                    $platform,
                    $platformId,
                    $phone,
                    null
                );


            if (
                !is_array($dbUser)
                ||
                empty(
                    $dbUser['id']
                )
            ) {

                return null;
            }


            return [

                'id' =>
                    (int)$dbUser['id'],

                'platform' =>
                    $platform,

                'platform_id' =>
                    $platformId,

                'phone' =>
                    $phone,

                'name' =>
                    $dbUser['name']
                    ?? '',

            ];
        }
        catch (Throwable $e) {

            Logger::write(
                'web_listener_error',
                [
                    'step' =>
                        'USER_RESOLUTION_EXCEPTION',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );


            return null;
        }
    }


    /**
     * ----------------------------------------------------------------------
     * Normalize BotEngine Result
     * ----------------------------------------------------------------------
     */
    protected function normalizeBotResult(
        mixed $result
    ): array {

        if (
            is_string($result)
        ) {

            return [

                'success' =>
                    true,

                'message' =>
                    $result,

            ];
        }


        if (
            is_array($result)
        ) {

            $message =
                $result['message']
                ??
                $result['text']
                ??
                $result['reply']
                ??
                null;


            if (
                $message === null
            ) {

                return [

                    'success' =>
                        true,

                    'data' =>
                        $result,

                ];
            }


            return [

                'success' =>
                    true,

                'message' =>
                    (string)$message,

                'continue' =>
                    $result['continue']
                    ?? null,

                'data' =>
                    $result,

            ];
        }


        if (
            $result === null
        ) {

            return [

                'success' =>
                    true,

                'message' =>
                    null,

            ];
        }


        return [

            'success' =>
                true,

            'data' =>
                $result,

        ];
    }


    /**
     * ----------------------------------------------------------------------
     * Handle Existing Escrow API
     * ----------------------------------------------------------------------
     */
    protected function handleEscrowApi(
        string $path,
        array $payload,
        string $requestId
    ): void {

        try {

            $routes = [

                '/api/escrow/verify' =>
                    'verify',

                '/api/escrow/release' =>
                    'release',

                '/api/escrow/payment' =>
                    'payment',

                '/api/escrow/payment/status' =>
                    'paymentStatus',

            ];


            if (
                !isset(
                    $routes[$path]
                )
            ) {

                $this->json(
                    [
                        'success' =>
                            false,

                        'message' =>
                            'Escrow endpoint not found.',

                        'request_id' =>
                            $requestId,
                    ],
                    404
                );

                return;
            }


            $controller =
                new EscrowApiController();


            $this->callController(
                $controller,
                $routes[$path],
                $payload,
                $requestId
            );
        }
        catch (Throwable $e) {

            Logger::write(
                'web_listener_error',
                [
                    'step' =>
                        'ESCROW_API_EXCEPTION',

                    'request_id' =>
                        $requestId,

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );


            $this->json(
                [
                    'success' =>
                        false,

                    'message' =>
                        'Unable to process escrow request.',

                    'request_id' =>
                        $requestId,
                ],
                500
            );
        }
    }


    /**
     * ----------------------------------------------------------------------
     * Call Escrow Controller
     * ----------------------------------------------------------------------
     */
    protected function callController(
        object $controller,
        string $method,
        array $payload,
        string $requestId
    ): void {

        try {

            if (
                !method_exists(
                    $controller,
                    $method
                )
            ) {

                Logger::write(
                    'web_listener_error',
                    [
                        'step' =>
                            'CONTROLLER_METHOD_NOT_FOUND',

                        'method' =>
                            $method,

                        'request_id' =>
                            $requestId,
                    ]
                );


                $this->json(
                    [
                        'success' =>
                            false,

                        'message' =>
                            'API operation unavailable.',

                        'request_id' =>
                            $requestId,
                    ],
                    500
                );

                return;
            }


            $result =
                $controller->{$method}(
                    $payload
                );


            if (
                is_array($result)
            ) {

                $result['request_id'] =
                    $result['request_id']
                    ??
                    $requestId;


                $status =
                    !empty(
                        $result['success']
                    )
                        ? 200
                        : 400;


                $this->json(
                    $result,
                    $status
                );

                return;
            }


            if (
                $result === null
            ) {

                return;
            }


            $this->json(
                [
                    'success' =>
                        true,

                    'data' =>
                        $result,

                    'request_id' =>
                        $requestId,
                ]
            );
        }
        catch (Throwable $e) {

            Logger::write(
                'web_listener_error',
                [
                    'step' =>
                        'CONTROLLER_EXCEPTION',

                    'method' =>
                        $method,

                    'request_id' =>
                        $requestId,

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );


            $this->json(
                [
                    'success' =>
                        false,

                    'message' =>
                        'Unable to process API request.',

                    'request_id' =>
                        $requestId,
                ],
                500
            );
        }
    }


    /**
     * ----------------------------------------------------------------------
     * Read Request Payload
     * ----------------------------------------------------------------------
     */
    protected function payload(): array
    {
        $contentType =
            strtolower(
                $_SERVER['CONTENT_TYPE']
                ??
                ''
            );


        /*
        |--------------------------------------------------------------------------
        | JSON
        |--------------------------------------------------------------------------
        */

        if (
            str_contains(
                $contentType,
                'application/json'
            )
        ) {

            $raw =
                file_get_contents(
                    'php://input'
                );


            if (
                !is_string($raw)
                ||
                trim($raw) === ''
            ) {

                return [];
            }


            $decoded =
                json_decode(
                    $raw,
                    true
                );


            return
                is_array($decoded)
                    ? $decoded
                    : [];
        }


        /*
        |--------------------------------------------------------------------------
        | Form POST
        |--------------------------------------------------------------------------
        */

        if (
            is_array($_POST)
        ) {

            return $_POST;
        }


        /*
        |--------------------------------------------------------------------------
        | Raw JSON Fallback
        |--------------------------------------------------------------------------
        */

        $raw =
            file_get_contents(
                'php://input'
            );


        if (
            is_string($raw)
            &&
            trim($raw) !== ''
        ) {

            $decoded =
                json_decode(
                    $raw,
                    true
                );


            if (
                is_array($decoded)
            ) {

                return $decoded;
            }
        }


        return [];
    }


    /**
     * ----------------------------------------------------------------------
     * API Authorization
     * ----------------------------------------------------------------------
     *
     * Accepted:
     *
     *     Authorization: Bearer API_KEY
     *
     * or:
     *
     *     X-API-Key: API_KEY
     *
     * or, when configured:
     *
     *     X-API-Secret: API_SECRET
     *
     * Signature support:
     *
     *     X-Timestamp
     *     X-Signature
     *
     * can be enabled through configuration.
     * ----------------------------------------------------------------------
     */
    protected function authorize(): bool
    {
        if (
            defined('PINGCHECKOUT_API_ENABLED')
            &&
            !PINGCHECKOUT_API_ENABLED
        ) {

            return false;
        }


        $authorization =
            $_SERVER['HTTP_AUTHORIZATION']
            ??
            '';


        if (
            $authorization === ''
        ) {

            $authorization =
                $_SERVER[
                    'REDIRECT_HTTP_AUTHORIZATION'
                ]
                ??
                '';
        }


        $apiKey = '';


        if (
            preg_match(
                '/Bearer\s+(.+)/i',
                $authorization,
                $matches
            )
        ) {

            $apiKey =
                trim(
                    $matches[1]
                );
        }


        if (
            $apiKey === ''
        ) {

            $apiKey =
                trim(
                    $_SERVER['HTTP_X_API_KEY']
                    ??
                    ''
                );
        }


        $configuredKey =
            defined(
                'PINGCHECKOUT_API_KEY'
            )
                ? (string)PINGCHECKOUT_API_KEY
                : '';


        if (
            $configuredKey === ''
        ) {

            Logger::write(
                'web_listener_error',
                [
                    'step' =>
                        'API_KEY_NOT_CONFIGURED',
                ]
            );

            return false;
        }


        if (
            $apiKey === ''
            ||
            !hash_equals(
                $configuredKey,
                $apiKey
            )
        ) {

            return false;
        }


        /*
        |--------------------------------------------------------------------------
        | Optional Request Signature
        |--------------------------------------------------------------------------
        */

        if (
            defined(
                'PINGCHECKOUT_API_SIGNATURE_ENABLED'
            )
            &&
            PINGCHECKOUT_API_SIGNATURE_ENABLED
        ) {

            return $this->verifySignature();
        }


        return true;
    }


    /**
     * ----------------------------------------------------------------------
     * Verify API Signature
     * ----------------------------------------------------------------------
     */
    protected function verifySignature(): bool
    {
        $timestamp =
            trim(
                $_SERVER[
                    'HTTP_X_TIMESTAMP'
                ]
                ??
                ''
            );


        $signature =
            trim(
                $_SERVER[
                    'HTTP_X_SIGNATURE'
                ]
                ??
                ''
            );


        if (
            $timestamp === ''
            ||
            $signature === ''
        ) {

            return false;
        }


        if (
            !ctype_digit(
                $timestamp
            )
        ) {

            return false;
        }


        $tolerance =
            defined(
                'PINGCHECKOUT_API_TIMESTAMP_TOLERANCE'
            )
                ? (int)
                    PINGCHECKOUT_API_TIMESTAMP_TOLERANCE
                : 300;


        if (
            abs(
                time()
                -
                (int)$timestamp
            )
            >
            $tolerance
        ) {

            return false;
        }


        $raw =
            file_get_contents(
                'php://input'
            );


        if (
            !is_string($raw)
        ) {

            $raw = '';
        }


        $secret =
            defined(
                'PINGCHECKOUT_API_SECRET'
            )
                ? (string)
                    PINGCHECKOUT_API_SECRET
                : '';


        if (
            $secret === ''
        ) {

            return false;
        }


        $expected =
            hash_hmac(
                'sha256',
                $timestamp . '.' . $raw,
                $secret
            );


        return hash_equals(
            $expected,
            $signature
        );
    }


    /**
     * ----------------------------------------------------------------------
     * Normalize Phone
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
            preg_replace(
                '/^(sms|whatsapp):/i',
                '',
                $phone
            )
            ??
            $phone;


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
        | Nigerian Local Format
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


    /**
     * ----------------------------------------------------------------------
     * JSON Response
     * ----------------------------------------------------------------------
     */
    protected function json(
        array $data,
        int $status = 200
    ): void {

        http_response_code(
            $status
        );


        header(
            'Content-Type: application/json; charset=utf-8'
        );


        header(
            'Cache-Control: no-store, no-cache, must-revalidate'
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
     * Request ID
     * ----------------------------------------------------------------------
     */
    protected function requestId(): string
    {
        try {

            return
                bin2hex(
                    random_bytes(
                        8
                    )
                );
        }
        catch (Throwable) {

            return
                uniqid(
                    'req_',
                    true
                );
        }
    }
}
?>
