<?php

declare(strict_types=1);

namespace Listeners\Ussd;

use Core\Logger;
use Models\User;
use Modules\BotEngine;
use Services\USSD\USSDProviderFactory;
use Throwable;

class UssdListener
{
    /**
     * ---------------------------------------------------------
     * Handle Incoming USSD Webhook
     * ---------------------------------------------------------
     */
    public function handle(): void
    {
        $raw = '';

        try {

            /*
            |--------------------------------------------------------------------------
            | Request Information
            |--------------------------------------------------------------------------
            */

            $method = strtoupper(
                (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
            );

            $uri = (string)(
                $_SERVER['REQUEST_URI']
                ?? '/ussd'
            );

            $raw = file_get_contents(
                'php://input'
            );

            if (!is_string($raw)) {
                $raw = '';
            }

            Logger::write(
                'ussd_listener',
                [
                    'step' => 'REQUEST_RECEIVED',
                    'method' => $method,
                    'uri' => $uri,
                    'get' => $_GET,
                    'post' => $_POST,
                    'raw' => $raw,
                    'content_type' =>
                        $_SERVER['CONTENT_TYPE']
                        ?? null,
                    'configured_provider' =>
                        defined('USSD_PROVIDER')
                            ? (string)USSD_PROVIDER
                            : null,
                    'time' => date('Y-m-d H:i:s'),
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Resolve Provider
            |--------------------------------------------------------------------------
            */

            $provider = $this->provider();

            if (!$provider) {

                Logger::write(
                    'ussd_listener_error',
                    [
                        'step' => 'PROVIDER_RESOLUTION_FAILED',
                    ]
                );

                $this->respondFallback(
                    'USSD service temporarily unavailable.'
                );

                return;
            }

            $providerName =
                $this->providerName(
                    $provider
                );

            Logger::write(
                'ussd_listener',
                [
                    'step' => 'PROVIDER_RESOLVED',
                    'provider' => $providerName,
                    'provider_class' => $provider::class,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | GET Request Handling
            |--------------------------------------------------------------------------
            |
            | Opening /ussd in a browser creates:
            |
            | GET /ussd
            |
            | There is no USSD session in that request.
            |
            | We therefore allow GET as an endpoint health/test request.
            |
            | If GET contains USSD parameters, however, we process them.
            |
            */

            $payload = $this->requestPayload(
                $raw,
                $method
            );

            Logger::write(
                'ussd_listener',
                [
                    'step' => 'PAYLOAD_PREPARED',
                    'provider' => $providerName,
                    'method' => $method,
                    'payload' => $payload,
                    'payload_count' => count($payload),
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Empty GET Request
            |--------------------------------------------------------------------------
            |
            | This is what happens when you simply visit:
            |
            | https://your-domain.com/ussd
            |
            */

            if (
                empty($payload)
                &&
                $method === 'GET'
            ) {

                Logger::write(
                    'ussd_listener',
                    [
                        'step' => 'GET_ENDPOINT_TEST',
                        'provider' => $providerName,
                        'message' =>
                            'GET request received without USSD parameters.',
                    ]
                );

                $this->respondProvider(
                    $provider,
                    'USSD service is online.',
                    false
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Empty Payload
            |--------------------------------------------------------------------------
            */

            if (empty($payload)) {

                Logger::write(
                    'ussd_listener_error',
                    [
                        'step' => 'EMPTY_PAYLOAD',
                        'provider' => $providerName,
                        'method' => $method,
                        'raw_length' => strlen($raw),
                    ]
                );

                $this->respondProvider(
                    $provider,
                    'Invalid USSD request.',
                    false
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Webhook
            |--------------------------------------------------------------------------
            */

            if (
                method_exists(
                    $provider,
                    'validateWebhook'
                )
            ) {

                $headers =
                    $this->requestHeaders();

                $valid =
                    $provider->validateWebhook(
                        $headers,
                        $raw
                    );

                Logger::write(
                    'ussd_listener',
                    [
                        'step' => 'WEBHOOK_VALIDATION',
                        'provider' => $providerName,
                        'valid' => $valid,
                    ]
                );

                if (!$valid) {

                    Logger::write(
                        'ussd_listener_error',
                        [
                            'step' => 'WEBHOOK_REJECTED',
                            'provider' => $providerName,
                        ]
                    );

                    $this->respondFallback(
                        'Unauthorized request.',
                        403
                    );

                    return;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Normalize Incoming Request
            |--------------------------------------------------------------------------
            |
            | Preferred interface method:
            |
            | normalizeRequest()
            |
            | Backward compatibility:
            |
            | normalizeIncoming()
            |
            */

            $request = null;

            if (
                method_exists(
                    $provider,
                    'normalizeRequest'
                )
            ) {

                Logger::write(
                    'ussd_listener',
                    [
                        'step' => 'NORMALIZER_SELECTED',
                        'method' => 'normalizeRequest',
                        'provider' => $providerName,
                    ]
                );

                $request =
                    $provider->normalizeRequest(
                        $payload
                    );
            }
            elseif (
                method_exists(
                    $provider,
                    'normalizeIncoming'
                )
            ) {

                Logger::write(
                    'ussd_listener',
                    [
                        'step' => 'NORMALIZER_SELECTED',
                        'method' => 'normalizeIncoming',
                        'provider' => $providerName,
                    ]
                );

                $request =
                    $provider->normalizeIncoming(
                        $payload
                    );
            }
            else {

                Logger::write(
                    'ussd_listener_error',
                    [
                        'step' => 'NORMALIZE_METHOD_NOT_FOUND',
                        'provider' => $providerName,
                        'class' => $provider::class,
                    ]
                );

                $this->respondFallback(
                    'USSD provider configuration error.'
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Normalization Result
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'ussd_listener',
                [
                    'step' => 'PROVIDER_NORMALIZATION_RESULT',
                    'provider' => $providerName,
                    'request' => $request,
                ]
            );

            if (!is_array($request)) {

                Logger::write(
                    'ussd_listener_error',
                    [
                        'step' =>
                            'NORMALIZATION_RETURNED_INVALID_TYPE',

                        'provider' =>
                            $providerName,

                        'type' =>
                            get_debug_type(
                                $request
                            ),
                    ]
                );

                $this->respondProvider(
                    $provider,
                    'Invalid USSD request.',
                    false
                );

                return;
            }

            if (
                !empty(
                    $request['error']
                )
            ) {

                Logger::write(
                    'ussd_listener_error',
                    [
                        'step' =>
                            'PROVIDER_NORMALIZATION_FAILED',

                        'provider' =>
                            $providerName,

                        'request' =>
                            $request,
                    ]
                );

                $this->respondProvider(
                    $provider,
                    'Invalid USSD request.',
                    false
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Extract Core USSD Fields
            |--------------------------------------------------------------------------
            */

            $phone = trim(
                (string)(
                    $request['phone']
                    ??
                    $request['phoneNumber']
                    ??
                    $payload['phoneNumber']
                    ??
                    $payload['phone']
                    ??
                    $payload['msisdn']
                    ??
                    ''
                )
            );

            $sessionId = trim(
                (string)(
                    $request['session_id']
                    ??
                    $request['sessionId']
                    ??
                    $payload['sessionId']
                    ??
                    $payload['session_id']
                    ??
                    ''
                )
            );

            $serviceCode = trim(
                (string)(
                    $request['service_code']
                    ??
                    $request['serviceCode']
                    ??
                    $payload['serviceCode']
                    ??
                    $payload['service_code']
                    ??
                    ''
                )
            );

            $text = trim(
                (string)(
                    $request['text']
                    ??
                    $payload['text']
                    ??
                    ''
                )
            );

            $network =
                $request['network']
                ??
                $payload['network']
                ??
                null;

            $networkCode =
                $request['network_code']
                ??
                $payload['networkCode']
                ??
                null;


            /*
            |--------------------------------------------------------------------------
            | Log Extracted Request
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'ussd_listener',
                [
                    'step' => 'FIELDS_EXTRACTED',
                    'provider' => $providerName,
                    'phone' => $phone,
                    'session_id' => $sessionId,
                    'service_code' => $serviceCode,
                    'text' => $text,
                    'network' => $network,
                    'network_code' => $networkCode,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Validate Required Fields
            |--------------------------------------------------------------------------
            */

            if (
                $phone === ''
                ||
                $sessionId === ''
            ) {

                Logger::write(
                    'ussd_listener_error',
                    [
                        'step' =>
                            'REQUIRED_FIELDS_MISSING',

                        'provider' =>
                            $providerName,

                        'phone' =>
                            $phone,

                        'session_id' =>
                            $sessionId,

                        'service_code' =>
                            $serviceCode,

                        'text' =>
                            $text,

                        'payload' =>
                            $payload,

                        'request' =>
                            $request,
                    ]
                );

                $this->respondProvider(
                    $provider,
                    'Unable to process your USSD request.',
                    false
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Normalize Phone
            |--------------------------------------------------------------------------
            */

            $phone =
                $this->normalizePhone(
                    $phone
                );


            Logger::write(
                'ussd_listener',
                [
                    'step' => 'REQUEST_NORMALIZED',
                    'provider' => $providerName,
                    'phone' => $phone,
                    'session_id' => $sessionId,
                    'service_code' => $serviceCode,
                    'text' => $text,
                    'network' => $network,
                    'network_code' => $networkCode,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Build Common Request Structure
            |--------------------------------------------------------------------------
            */

            $request['provider'] =
                $request['provider']
                ??
                $providerName;

            $request['platform'] =
                'ussd';

            $request['phone'] =
                $phone;

            $request['session_id'] =
                $sessionId;

            $request['service_code'] =
                $serviceCode;

            $request['text'] =
                $text;

            $request['network'] =
                $network;

            $request['network_code'] =
                $networkCode;

            $request['raw'] =
                $request['raw']
                ??
                $payload;


            /*
            |--------------------------------------------------------------------------
            | Platform ID
            |--------------------------------------------------------------------------
            |
            | USSD users are identified by their normalized phone number.
            |
            */

            $request['platform_id'] =
                trim(
                    (string)(
                        $request['platform_id']
                        ??
                        $phone
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | Resolve User
            |--------------------------------------------------------------------------
            */

            $user =
                $this->resolveUser(
                    $request
                );

            if (!$user) {

                Logger::write(
                    'ussd_listener_error',
                    [
                        'step' =>
                            'USER_RESOLUTION_FAILED',

                        'provider' =>
                            $providerName,

                        'phone' =>
                            $phone,

                        'session_id' =>
                            $sessionId,
                    ]
                );

                $this->respondProvider(
                    $provider,
                    'Unable to identify your account.',
                    false
                );

                return;
            }


            Logger::write(
                'ussd_listener',
                [
                    'step' => 'USER_RESOLVED',
                    'provider' => $providerName,
                    'user_id' =>
                        $user['id']
                        ?? null,
                    'platform_id' =>
                        $user['platform_id']
                        ?? null,
                    'phone' =>
                        $user['phone']
                        ?? null,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Process Through BotEngine
            |--------------------------------------------------------------------------
            */

            $response =
                $this->processMessage(
                    $user,
                    $request
                );


            Logger::write(
                'ussd_listener',
                [
                    'step' =>
                        'BOT_RESPONSE_RECEIVED',

                    'provider' =>
                        $providerName,

                    'user_id' =>
                        $user['id']
                        ?? null,

                    'response' =>
                        $response,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Invalid Bot Response
            |--------------------------------------------------------------------------
            */

            if (!is_array($response)) {

                Logger::write(
                    'ussd_listener_error',
                    [
                        'step' =>
                            'INVALID_BOT_RESPONSE',

                        'provider' =>
                            $providerName,

                        'response_type' =>
                            get_debug_type(
                                $response
                            ),
                    ]
                );

                $response = [
                    'message' =>
                        'Unable to process your request.',

                    'continue' =>
                        false,
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Extract Message
            |--------------------------------------------------------------------------
            */

            $message = trim(
                (string)(
                    $response['message']
                    ??
                    $response['text']
                    ??
                    $response['reply']
                    ??
                    ''
                )
            );

            $continue =
                (bool)(
                    $response['continue']
                    ??
                    false
                );


            /*
            |--------------------------------------------------------------------------
            | Empty Message Protection
            |--------------------------------------------------------------------------
            */

            if ($message === '') {

                Logger::write(
                    'ussd_listener_error',
                    [
                        'step' =>
                            'EMPTY_RESPONSE_MESSAGE',

                        'provider' =>
                            $providerName,

                        'user_id' =>
                            $user['id']
                            ?? null,
                    ]
                );

                $message =
                    'Unable to process your request.';

                $continue = false;
            }


            /*
            |--------------------------------------------------------------------------
            | Send Provider Response
            |--------------------------------------------------------------------------
            */

            $this->respondProvider(
                $provider,
                $message,
                $continue
            );
        }
        catch (Throwable $e) {

            Logger::write(
                'ussd_listener_error',
                [
                    'step' =>
                        'LISTENER_EXCEPTION',

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

            $this->respondFallback(
                'USSD service temporarily unavailable.'
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * Resolve Provider
     * ---------------------------------------------------------
     */
    protected function provider(): ?object
    {
        try {

            $providerName =
                defined('USSD_PROVIDER')
                    ? strtolower(
                        trim(
                            (string)USSD_PROVIDER
                        )
                    )
                    : '';

            Logger::write(
                'ussd_listener',
                [
                    'step' =>
                        'PROVIDER_LOOKUP',

                    'provider' =>
                        $providerName,
                ]
            );


            if ($providerName === '') {

                Logger::write(
                    'ussd_listener_error',
                    [
                        'step' =>
                            'USSD_PROVIDER_NOT_CONFIGURED',
                    ]
                );

                return null;
            }


            if (
                !class_exists(
                    USSDProviderFactory::class
                )
            ) {

                Logger::write(
                    'ussd_listener_error',
                    [
                        'step' =>
                            'FACTORY_CLASS_NOT_FOUND',

                        'class' =>
                            USSDProviderFactory::class,
                    ]
                );

                return null;
            }


            $factory =
                new USSDProviderFactory();


            Logger::write(
                'ussd_listener',
                [
                    'step' =>
                        'FACTORY_CREATED',

                    'factory' =>
                        $factory::class,
                ]
            );


            $provider = null;


            if (
                method_exists(
                    $factory,
                    'make'
                )
            ) {

                Logger::write(
                    'ussd_listener',
                    [
                        'step' =>
                            'FACTORY_METHOD',

                        'method' =>
                            'make',

                        'provider' =>
                            $providerName,
                    ]
                );

                $provider =
                    $factory->make(
                        $providerName
                    );
            }
            elseif (
                method_exists(
                    $factory,
                    'create'
                )
            ) {

                Logger::write(
                    'ussd_listener',
                    [
                        'step' =>
                            'FACTORY_METHOD',

                        'method' =>
                            'create',

                        'provider' =>
                            $providerName,
                    ]
                );

                $provider =
                    $factory->create(
                        $providerName
                    );
            }
            elseif (
                method_exists(
                    $factory,
                    'get'
                )
            ) {

                Logger::write(
                    'ussd_listener',
                    [
                        'step' =>
                            'FACTORY_METHOD',

                        'method' =>
                            'get',

                        'provider' =>
                            $providerName,
                    ]
                );

                $provider =
                    $factory->get(
                        $providerName
                    );
            }
            else {

                Logger::write(
                    'ussd_listener_error',
                    [
                        'step' =>
                            'FACTORY_METHOD_NOT_FOUND',

                        'factory' =>
                            $factory::class,

                        'provider' =>
                            $providerName,
                    ]
                );

                return null;
            }


            if (!is_object($provider)) {

                Logger::write(
                    'ussd_listener_error',
                    [
                        'step' =>
                            'FACTORY_RETURNED_INVALID_PROVIDER',

                        'provider' =>
                            $providerName,

                        'returned_type' =>
                            get_debug_type(
                                $provider
                            ),
                    ]
                );

                return null;
            }


            Logger::write(
                'ussd_listener',
                [
                    'step' =>
                        'PROVIDER_OBJECT_CREATED',

                    'provider' =>
                        $providerName,

                    'class' =>
                        $provider::class,
                ]
            );


            return $provider;
        }
        catch (Throwable $e) {

            Logger::write(
                'ussd_listener_error',
                [
                    'step' =>
                        'PROVIDER_FACTORY_EXCEPTION',

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

            return null;
        }
    }


    /**
     * ---------------------------------------------------------
     * Resolve User
     * ---------------------------------------------------------
     */
    protected function resolveUser(
        array $request
    ): ?array {

        try {

            $userModel =
                new User();


            $platformId =
                trim(
                    (string)(
                        $request['platform_id']
                        ??
                        $request['phone']
                        ??
                        ''
                    )
                );


            $phone =
                trim(
                    (string)(
                        $request['phone']
                        ??
                        ''
                    )
                );


            if ($platformId === '') {

                Logger::write(
                    'ussd_listener_error',
                    [
                        'step' =>
                            'EMPTY_PLATFORM_ID',

                        'request' =>
                            $request,
                    ]
                );

                return null;
            }


            Logger::write(
                'ussd_listener',
                [
                    'step' =>
                        'USER_LOOKUP_START',

                    'platform' =>
                        'ussd',

                    'platform_id' =>
                        $platformId,

                    'phone' =>
                        $phone,
                ]
            );


            $dbUser =
                $userModel->findOrCreatePlatformUser(
                    'ussd',
                    $platformId,
                    $phone !== ''
                        ? $phone
                        : null,
                    null
                );


            Logger::write(
                'ussd_listener',
                [
                    'step' =>
                        'DATABASE_USER_RESULT',

                    'platform' =>
                        'ussd',

                    'platform_id' =>
                        $platformId,

                    'user_id' =>
                        is_array($dbUser)
                            ? (
                                $dbUser['id']
                                ?? null
                            )
                            : null,
                ]
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
                    'ussd',

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
                'ussd_listener_error',
                [
                    'step' =>
                        'USER_RESOLUTION_EXCEPTION',

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

            return null;
        }
    }


    /**
     * ---------------------------------------------------------
     * Process USSD Message
     * ---------------------------------------------------------
     */
    protected function processMessage(
        array $user,
        array $request
    ): ?array {

        try {

            $message = [

                'platform' =>
                    'ussd',

                'provider' =>
                    $request['provider']
                    ?? null,

                'phone' =>
                    $request['phone']
                    ?? null,

                'platform_id' =>
                    $request['platform_id']
                    ?? null,

                'session_id' =>
                    $request['session_id']
                    ?? null,

                'service_code' =>
                    $request['service_code']
                    ?? null,

                'type' =>
                    'text',

                'text' =>
                    $request['text']
                    ?? '',

                'network' =>
                    $request['network']
                    ?? null,

                'network_code' =>
                    $request['network_code']
                    ?? null,

                'request_type' =>
                    $request['request_type']
                    ?? null,

                'raw' =>
                    $request['raw']
                    ?? [],
            ];


            Logger::write(
                'before_ussd_bot_engine',
                [
                    'step' =>
                        'PROCESS_START',

                    'user' =>
                        $user,

                    'message' =>
                        $message,
                ]
            );


            $bot =
                new BotEngine();


            $result =
                $bot->process(
                    $user,
                    $message
                );


            Logger::write(
                'after_ussd_bot_engine',
                [
                    'step' =>
                        'PROCESS_COMPLETE',

                    'user_id' =>
                        $user['id']
                        ?? null,

                    'result_type' =>
                        get_debug_type(
                            $result
                        ),

                    'result' =>
                        $result,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | String Response
            |--------------------------------------------------------------------------
            */

            if (
                is_string(
                    $result
                )
            ) {

                return $this->extractResponse(
                    $result
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Array Response
            |--------------------------------------------------------------------------
            */

            if (
                is_array(
                    $result
                )
            ) {

                $response =
                    $result['message']
                    ??
                    $result['text']
                    ??
                    $result['reply']
                    ??
                    null;


                if (
                    is_string(
                        $response
                    )
                    &&
                    trim(
                        $response
                    ) !== ''
                ) {

                    return $this->extractResponse(
                        $response,
                        $result
                    );
                }


                if (
                    isset(
                        $result['continue']
                    )
                    ||
                    isset(
                        $result['end']
                    )
                ) {

                    return [

                        'message' =>
                            trim(
                                (string)(
                                    $result['message']
                                    ??
                                    $result['text']
                                    ??
                                    $result['reply']
                                    ??
                                    ''
                                )
                            ),

                        'continue' =>
                            isset(
                                $result['continue']
                            )
                                ? (bool)$result['continue']
                                : !(
                                    (bool)(
                                        $result['end']
                                        ?? false
                                    )
                                ),

                    ];
                }
            }


            Logger::write(
                'ussd_listener_error',
                [
                    'step' =>
                        'EMPTY_BOT_RESPONSE',

                    'user_id' =>
                        $user['id']
                        ?? null,

                    'result' =>
                        $result,
                ]
            );


            return null;
        }
        catch (Throwable $e) {

            Logger::write(
                'ussd_bot_engine_error',
                [
                    'step' =>
                        'BOT_ENGINE_EXCEPTION',

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

            return null;
        }
    }


    /**
     * ---------------------------------------------------------
     * Extract Bot Response
     * ---------------------------------------------------------
     */
    protected function extractResponse(
        string $response,
        array $result = []
    ): array {

        $response =
            trim(
                $response
            );


        /*
        |--------------------------------------------------------------------------
        | END
        |--------------------------------------------------------------------------
        */

        if (
            preg_match(
                '/^END(?:\s+|$)/i',
                $response
            )
        ) {

            $message =
                preg_replace(
                    '/^END(?:\s+|$)/i',
                    '',
                    $response
                );


            return [

                'message' =>
                    trim(
                        (string)$message
                    ),

                'continue' =>
                    false,

            ];
        }


        /*
        |--------------------------------------------------------------------------
        | CON
        |--------------------------------------------------------------------------
        */

        if (
            preg_match(
                '/^CON(?:\s+|$)/i',
                $response
            )
        ) {

            $message =
                preg_replace(
                    '/^CON(?:\s+|$)/i',
                    '',
                    $response
                );


            return [

                'message' =>
                    trim(
                        (string)$message
                    ),

                'continue' =>
                    true,

            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Structured Continue
        |--------------------------------------------------------------------------
        */

        if (
            array_key_exists(
                'continue',
                $result
            )
        ) {

            return [

                'message' =>
                    $response,

                'continue' =>
                    (bool)$result['continue'],

            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Structured End
        |--------------------------------------------------------------------------
        */

        if (
            array_key_exists(
                'end',
                $result
            )
        ) {

            return [

                'message' =>
                    $response,

                'continue' =>
                    !(
                        (bool)$result['end']
                    ),

            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Safe Default
        |--------------------------------------------------------------------------
        */

        return [

            'message' =>
                $response,

            'continue' =>
                false,

        ];
    }


    /**
     * ---------------------------------------------------------
     * Send Provider Response
     * ---------------------------------------------------------
     */
    protected function respondProvider(
        object $provider,
        string $message,
        bool $continue
    ): void {

        try {

            $formatted = null;


            /*
            |--------------------------------------------------------------------------
            | Preferred Interface Method
            |--------------------------------------------------------------------------
            */

            if (
                method_exists(
                    $provider,
                    'response'
                )
            ) {

                Logger::write(
                    'ussd_listener',
                    [
                        'step' =>
                            'RESPONSE_METHOD_SELECTED',

                        'method' =>
                            'response',

                        'provider' =>
                            $this->providerName(
                                $provider
                            ),
                    ]
                );


                $formatted =
                    $provider->response(
                        $message,
                        $continue
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | Backward Compatibility
            |--------------------------------------------------------------------------
            */

            elseif (
                method_exists(
                    $provider,
                    'formatResponse'
                )
            ) {

                Logger::write(
                    'ussd_listener',
                    [
                        'step' =>
                            'RESPONSE_METHOD_SELECTED',

                        'method' =>
                            'formatResponse',

                        'provider' =>
                            $this->providerName(
                                $provider
                            ),
                    ]
                );


                $formatted =
                    $provider->formatResponse(
                        $message,
                        $continue
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | No Provider Response Method
            |--------------------------------------------------------------------------
            */

            else {

                Logger::write(
                    'ussd_listener_error',
                    [
                        'step' =>
                            'RESPONSE_METHOD_NOT_FOUND',

                        'provider' =>
                            $this->providerName(
                                $provider
                            ),

                        'class' =>
                            $provider::class,
                    ]
                );


                $formatted =
                    (
                        $continue
                            ? 'CON '
                            : 'END '
                    )
                    .
                    trim(
                        $message
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | Ensure String
            |--------------------------------------------------------------------------
            */

            if (
                !is_string(
                    $formatted
                )
                ||
                trim(
                    $formatted
                ) === ''
            ) {

                $formatted =
                    (
                        $continue
                            ? 'CON '
                            : 'END '
                    )
                    .
                    trim(
                        $message
                    );
            }


            Logger::write(
                'ussd_listener',
                [
                    'step' =>
                        'RESPONSE_SENT',

                    'provider' =>
                        $this->providerName(
                            $provider
                        ),

                    'continue' =>
                        $continue,

                    'message' =>
                        $message,

                    'response' =>
                        $formatted,
                ]
            );


            if (
                !headers_sent()
            ) {

                header(
                    'Content-Type: text/plain; charset=utf-8'
                );
            }


            http_response_code(
                200
            );


            echo $formatted;
        }
        catch (Throwable $e) {

            Logger::write(
                'ussd_listener_error',
                [
                    'step' =>
                        'PROVIDER_RESPONSE_FAILED',

                    'provider' =>
                        $this->providerName(
                            $provider
                        ),

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


            $this->respondFallback(
                $message,
                200
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * Fallback Response
     * ---------------------------------------------------------
     */
    protected function respondFallback(
        string $message,
        int $status = 200
    ): void {

        try {

            if (
                !headers_sent()
            ) {

                header(
                    'Content-Type: text/plain; charset=utf-8'
                );
            }


            http_response_code(
                $status
            );


            echo 'END '
                .
                trim(
                    $message
                );
        }
        catch (Throwable $e) {

            Logger::write(
                'ussd_listener_error',
                [
                    'step' =>
                        'FALLBACK_RESPONSE_FAILED',

                    'message' =>
                        $e->getMessage(),
                ]
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * Build Request Payload
     * ---------------------------------------------------------
     */
    protected function requestPayload(
        ?string $raw = null,
        string $method = 'POST'
    ): array {

        /*
        |--------------------------------------------------------------------------
        | POST Form Data
        |--------------------------------------------------------------------------
        */

        if (
            !empty(
                $_POST
            )
        ) {

            Logger::write(
                'ussd_listener',
                [
                    'step' =>
                        'PAYLOAD_SOURCE_POST',

                    'payload' =>
                        $_POST,
                ]
            );


            return $_POST;
        }


        /*
        |--------------------------------------------------------------------------
        | GET Query Parameters
        |--------------------------------------------------------------------------
        |
        | Useful for testing:
        |
        | /ussd?phoneNumber=08012345678
        | &sessionId=TEST123
        | &serviceCode=*123#
        | &text=
        |
        */

        if (
            !empty(
                $_GET
            )
        ) {

            Logger::write(
                'ussd_listener',
                [
                    'step' =>
                        'PAYLOAD_SOURCE_GET',

                    'payload' =>
                        $_GET,
                ]
            );


            return $_GET;
        }


        /*
        |--------------------------------------------------------------------------
        | Raw JSON
        |--------------------------------------------------------------------------
        */

        if (
            is_string(
                $raw
            )
            &&
            trim(
                $raw
            ) !== ''
        ) {

            $decoded =
                json_decode(
                    $raw,
                    true
                );


            if (
                is_array(
                    $decoded
                )
                &&
                !empty(
                    $decoded
                )
            ) {

                Logger::write(
                    'ussd_listener',
                    [
                        'step' =>
                            'PAYLOAD_SOURCE_JSON',

                        'payload' =>
                            $decoded,
                    ]
                );


                return $decoded;
            }


            /*
            |--------------------------------------------------------------------------
            | URL Encoded Raw Body
            |--------------------------------------------------------------------------
            */

            $parsed = [];


            parse_str(
                $raw,
                $parsed
            );


            if (
                is_array(
                    $parsed
                )
                &&
                !empty(
                    $parsed
                )
            ) {

                Logger::write(
                    'ussd_listener',
                    [
                        'step' =>
                            'PAYLOAD_SOURCE_RAW_FORM',

                        'payload' =>
                            $parsed,
                    ]
                );


                return $parsed;
            }
        }


        return [];
    }


    /**
     * ---------------------------------------------------------
     * Request Headers
     * ---------------------------------------------------------
     */
    protected function requestHeaders(): array
    {
        $headers = [];


        if (
            function_exists(
                'getallheaders'
            )
        ) {

            $serverHeaders =
                getallheaders();


            if (
                is_array(
                    $serverHeaders
                )
            ) {

                $headers =
                    $serverHeaders;
            }
        }


        foreach (
            $_SERVER
            as $key => $value
        ) {

            if (
                !is_string(
                    $key
                )
                ||
                !is_string(
                    $value
                )
            ) {
                continue;
            }


            if (
                str_starts_with(
                    $key,
                    'HTTP_'
                )
            ) {

                $header =
                    str_replace(
                        ' ',
                        '-',
                        ucwords(
                            strtolower(
                                str_replace(
                                    '_',
                                    ' ',
                                    substr(
                                        $key,
                                        5
                                    )
                                )
                            )
                        )
                    );


                if (
                    !isset(
                        $headers[$header]
                    )
                ) {

                    $headers[$header] =
                        $value;
                }
            }
        }


        return $headers;
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
                '/^(tel:|sms:|ussd:|whatsapp:)/i',
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
        | Nigerian Local Number
        |--------------------------------------------------------------------------
        */

        if (
            str_starts_with(
                $phone,
                '0'
            )
            &&
            strlen(
                $phone
            ) === 11
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
     * ---------------------------------------------------------
     * Provider Name
     * ---------------------------------------------------------
     */
    protected function providerName(
        object $provider
    ): string {

        try {

            if (
                method_exists(
                    $provider,
                    'name'
                )
            ) {

                $name =
                    $provider->name();


                if (
                    is_string(
                        $name
                    )
                    &&
                    trim(
                        $name
                    ) !== ''
                ) {

                    return trim(
                        $name
                    );
                }
            }
        }
        catch (Throwable $e) {

            Logger::write(
                'ussd_listener_error',
                [
                    'step' =>
                        'PROVIDER_NAME_FAILED',

                    'class' =>
                        $provider::class,

                    'message' =>
                        $e->getMessage(),
                ]
            );
        }


        return $provider::class;
    }
}