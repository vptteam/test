<?php

declare(strict_types=1);

namespace Services\Payments;

use Core\Logger;
use Throwable;

class PaystackGateway
{
    protected string $secret;

    protected string $baseUrl;

    protected string $initializeEndpoint;

    public function __construct()
    {
        $this->secret =
            trim(
                (string) PAYSTACK_SECRET_KEY
            );

        $this->baseUrl =
            rtrim(
                (string) PAYSTACK_BASE_URL,
                '/'
            );

        $this->initializeEndpoint =
            $this->baseUrl .
            '/transaction/initialize';

        Logger::write(
            'paystack_gateway',
            [
                'step' => 'CONSTRUCTOR',
                'base_url' => $this->baseUrl
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * Initialize Payment
     * ---------------------------------------------------------
     *
     * $amount is the amount in NGN.
     *
     * Example:
     *
     *     25000
     *
     * becomes:
     *
     *     2500000 kobo
     *
     * The returned reference is the actual Paystack
     * transaction reference.
     *
     * This method DOES NOT modify the escrow.
     *
     * ---------------------------------------------------------
     */
    public function initialize(
        float $amount,
        string $email,
        string $reference,
        string $callback,
        array $metadata = []
    ): array {

        $reference = trim($reference);
        $email = trim($email);
        $callback = trim($callback);

        try {

            Logger::write(
                'paystack_gateway',
                [
                    'step' => 'INITIALIZE_START',
                    'amount_ngn' => $amount,
                    'email' => $email,
                    'reference' => $reference,
                    'callback' => $callback,
                    'metadata' => $metadata
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Validate Configuration
            |--------------------------------------------------------------------------
            */

            if ($this->secret === '') {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' => 'SECRET_KEY_MISSING',
                        'reference' => $reference
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Paystack configuration is incomplete.'
                ];
            }


            if ($this->baseUrl === '') {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' => 'BASE_URL_MISSING',
                        'reference' => $reference
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Paystack configuration is invalid.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Reference
            |--------------------------------------------------------------------------
            */

            if ($reference === '') {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' => 'REFERENCE_MISSING'
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Payment reference is required.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Email
            |--------------------------------------------------------------------------
            */

            if (
                $email === ''
                ||
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' => 'INVALID_EMAIL',
                        'reference' => $reference
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'A valid payment email is required.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Callback
            |--------------------------------------------------------------------------
            */

            if ($callback === '') {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' => 'CALLBACK_MISSING',
                        'reference' => $reference
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Payment callback URL is required.'
                ];
            }


            if (
                !filter_var(
                    $callback,
                    FILTER_VALIDATE_URL
                )
            ) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' => 'CALLBACK_INVALID',
                        'reference' => $reference,
                        'callback' => $callback
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Payment callback URL is invalid.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Amount
            |--------------------------------------------------------------------------
            */

            if (
                !is_finite($amount)
                ||
                $amount <= 0
            ) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' => 'INVALID_AMOUNT',
                        'reference' => $reference,
                        'amount' => $amount
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Invalid payment amount.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Convert NGN -> Kobo
            |--------------------------------------------------------------------------
            |
            | Paystack expects the amount in the smallest currency unit.
            |
            | NGN 25,000.00
            |
            | becomes:
            |
            | 2,500,000 kobo
            |
            |--------------------------------------------------------------------------
            */

            $amountKobo =
                (int) round(
                    $amount * 100
                );


            if ($amountKobo <= 0) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' => 'INVALID_KOBO_AMOUNT',
                        'reference' => $reference,
                        'amount_ngn' => $amount,
                        'amount_kobo' => $amountKobo
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Invalid payment amount.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Metadata
            |--------------------------------------------------------------------------
            */

            if (!is_array($metadata)) {

                $metadata = [];
            }


            /*
            |--------------------------------------------------------------------------
            | Build Payload
            |--------------------------------------------------------------------------
            */

            $payload = [

                'amount' =>
                    $amountKobo,

                'email' =>
                    $email,

                'reference' =>
                    $reference,

                'currency' =>
                    'NGN',

                'callback_url' =>
                    $callback,

                'metadata' =>
                    $metadata
            ];


            Logger::write(
                'paystack_gateway',
                [
                    'step' => 'INITIALIZE_PAYLOAD_READY',
                    'reference' => $reference,
                    'amount_ngn' => $amount,
                    'amount_kobo' => $amountKobo,
                    'metadata' => $metadata
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Encode JSON
            |--------------------------------------------------------------------------
            */

            $jsonPayload =
                json_encode(
                    $payload,
                    JSON_UNESCAPED_SLASHES
                    |
                    JSON_UNESCAPED_UNICODE
                    |
                    JSON_THROW_ON_ERROR
                );


            /*
            |--------------------------------------------------------------------------
            | Initialize cURL
            |--------------------------------------------------------------------------
            */

            $ch =
                curl_init(
                    $this->initializeEndpoint
                );


            if ($ch === false) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' => 'CURL_INIT_FAILED',
                        'reference' => $reference
                    ]
                );

                return [
                    'success' => false,
                    'retry' => true,
                    'message' =>
                        'Unable to initialize Paystack connection.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Configure cURL
            |--------------------------------------------------------------------------
            */

            curl_setopt_array(
                $ch,
                [

                    CURLOPT_RETURNTRANSFER =>
                        true,

                    CURLOPT_POST =>
                        true,

                    CURLOPT_POSTFIELDS =>
                        $jsonPayload,

                    CURLOPT_CONNECTTIMEOUT =>
                        10,

                    CURLOPT_TIMEOUT =>
                        60,

                    CURLOPT_FOLLOWLOCATION =>
                        false,

                    CURLOPT_HTTPHEADER => [

                        'Authorization: Bearer '
                        .
                        $this->secret,

                        'Content-Type: application/json',

                        'Accept: application/json'
                    ]
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Execute Request
            |--------------------------------------------------------------------------
            */

            $response =
                curl_exec(
                    $ch
                );


            $httpCode =
                (int) curl_getinfo(
                    $ch,
                    CURLINFO_HTTP_CODE
                );


            $curlError =
                curl_error(
                    $ch
                );


            $curlErrno =
                curl_errno(
                    $ch
                );


            curl_close(
                $ch
            );


            /*
            |--------------------------------------------------------------------------
            | Log Response
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'paystack_gateway',
                [
                    'step' => 'INITIALIZE_RESPONSE',
                    'reference' => $reference,
                    'http_code' => $httpCode,
                    'curl_errno' => $curlErrno,
                    'curl_error' => $curlError,
                    'response' =>
                        is_string($response)
                        ? $response
                        : null
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | cURL Failure
            |--------------------------------------------------------------------------
            */

            if ($curlError !== '') {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' => 'INITIALIZE_CURL_ERROR',
                        'reference' => $reference,
                        'curl_errno' => $curlErrno,
                        'message' => $curlError
                    ]
                );

                return [
                    'success' => false,
                    'retry' => true,
                    'message' =>
                        'Unable to connect to Paystack.',
                    'reference' =>
                        $reference
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Invalid HTTP Response
            |--------------------------------------------------------------------------
            */

            if ($httpCode <= 0) {

                return [
                    'success' => false,
                    'retry' => true,
                    'message' =>
                        'No valid response was received from Paystack.',
                    'reference' =>
                        $reference
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Response Must Be String
            |--------------------------------------------------------------------------
            */

            if (!is_string($response)) {

                return [
                    'success' => false,
                    'retry' => true,
                    'message' =>
                        'Invalid response from Paystack.',
                    'reference' =>
                        $reference,
                    'http_code' =>
                        $httpCode
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Decode Response
            |--------------------------------------------------------------------------
            */

            try {

                $decoded =
                    json_decode(
                        $response,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );

            } catch (Throwable $e) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' => 'INITIALIZE_JSON_ERROR',
                        'reference' => $reference,
                        'http_code' => $httpCode,
                        'message' => $e->getMessage(),
                        'response' => $response
                    ]
                );

                return [
                    'success' => false,
                    'retry' =>
                        $httpCode >= 500
                        ||
                        $httpCode === 429,
                    'message' =>
                        'Invalid Paystack response.',
                    'reference' =>
                        $reference,
                    'http_code' =>
                        $httpCode
                ];
            }


            if (!is_array($decoded)) {

                return [
                    'success' => false,
                    'retry' => true,
                    'message' =>
                        'Invalid Paystack response.',
                    'reference' =>
                        $reference,
                    'http_code' =>
                        $httpCode
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Paystack API Status
            |--------------------------------------------------------------------------
            */

            $apiStatus =
                (bool)(
                    $decoded['status']
                    ?? false
                );


            $apiMessage =
                trim(
                    (string)(
                        $decoded['message']
                        ?? ''
                    )
                );


            if (!$apiStatus) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' => 'INITIALIZE_PAYSTACK_FAILED',
                        'reference' => $reference,
                        'http_code' => $httpCode,
                        'message' => $apiMessage,
                        'raw' => $decoded
                    ]
                );

                return [
                    'success' => false,

                    'retry' =>
                        $httpCode >= 500
                        ||
                        $httpCode === 429,

                    'message' =>
                        $apiMessage !== ''
                        ? $apiMessage
                        : 'Payment initialization failed.',

                    'reference' =>
                        $reference,

                    'http_code' =>
                        $httpCode,

                    'raw' =>
                        $decoded
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Extract Data
            |--------------------------------------------------------------------------
            */

            $data =
                $decoded['data']
                ?? null;


            if (!is_array($data)) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' => 'INITIALIZE_DATA_INVALID',
                        'reference' => $reference,
                        'raw' => $decoded
                    ]
                );

                return [
                    'success' => false,
                    'retry' => true,
                    'message' =>
                        'Invalid payment response from Paystack.',
                    'reference' =>
                        $reference,
                    'raw' =>
                        $decoded
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Paystack Reference
            |--------------------------------------------------------------------------
            */

            $paystackReference =
                trim(
                    (string)(
                        $data['reference']
                        ?? ''
                    )
                );


            if ($paystackReference === '') {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' =>
                            'INITIALIZE_REFERENCE_NOT_RETURNED',

                        'reference' =>
                            $reference,

                        'data' =>
                            $data
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Paystack did not return a transaction reference.',
                    'reference' =>
                        $reference,
                    'raw' =>
                        $decoded
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Verify Reference Integrity
            |--------------------------------------------------------------------------
            |
            | We asked Paystack to create a transaction using $reference.
            |
            | Paystack must return the same reference.
            |--------------------------------------------------------------------------
            */

            if (
                !hash_equals(
                    strtoupper($reference),
                    strtoupper($paystackReference)
                )
            ) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' =>
                            'INITIALIZE_REFERENCE_MISMATCH',

                        'requested_reference' =>
                            $reference,

                        'returned_reference' =>
                            $paystackReference
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Paystack transaction reference mismatch.',
                    'reference' =>
                        $reference,
                    'returned_reference' =>
                        $paystackReference,
                    'raw' =>
                        $decoded
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Authorization URL
            |--------------------------------------------------------------------------
            */

            $authorizationUrl =
                trim(
                    (string)(
                        $data['authorization_url']
                        ?? ''
                    )
                );


            if ($authorizationUrl === '') {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' =>
                            'AUTHORIZATION_URL_MISSING',

                        'reference' =>
                            $reference,

                        'data' =>
                            $data
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Paystack did not return a payment link.',
                    'reference' =>
                        $paystackReference,
                    'raw' =>
                        $decoded
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Access Code
            |--------------------------------------------------------------------------
            */

            $accessCode =
                $data['access_code']
                ?? null;


            /*
            |--------------------------------------------------------------------------
            | Initialization Complete
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'paystack_gateway',
                [
                    'step' =>
                        'INITIALIZE_SUCCESS',

                    'reference' =>
                        $paystackReference,

                    'amount_ngn' =>
                        $amount,

                    'amount_kobo' =>
                        $amountKobo,

                    'http_code' =>
                        $httpCode
                ]
            );


            return [

                'success' =>
                    true,

                'retry' =>
                    false,

                'reference' =>
                    $paystackReference,

                'authorization_url' =>
                    $authorizationUrl,

                'access_code' =>
                    $accessCode,

                'amount' =>
                    $amount,

                'amount_kobo' =>
                    $amountKobo,

                'data' =>
                    $data,

                'raw' =>
                    $decoded
            ];


        } catch (Throwable $e) {

            Logger::write(
                'paystack_gateway_error',
                [
                    'step' =>
                        'INITIALIZE_EXCEPTION',

                    'reference' =>
                        $reference,

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),

                    'trace' =>
                        $e->getTraceAsString()
                ]
            );

            return [

                'success' =>
                    false,

                'retry' =>
                    true,

                'message' =>
                    'Paystack payment initialization failed.',

                'reference' =>
                    $reference
            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * Verify Payment
     * ---------------------------------------------------------
     *
     * IMPORTANT:
     *
     * $reference MUST be the Paystack transaction reference.
     *
     * Example:
     *
     *     ESC-SDM-000037-A1B2C3D4
     *
     * It is NOT:
     *
     *     SDM-000037
     *
     * This method only verifies the transaction.
     *
     * It DOES NOT modify escrow status.
     *
     * ---------------------------------------------------------
     */
    public function verify(
        string $reference
    ): array {

        $reference =
            trim(
                $reference
            );


        try {

            /*
            |--------------------------------------------------------------------------
            | Validate Configuration
            |--------------------------------------------------------------------------
            */

            if ($this->secret === '') {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' =>
                            'VERIFY_SECRET_KEY_MISSING',

                        'reference' =>
                            $reference
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Paystack configuration is incomplete.',
                    'reference' =>
                        $reference
                ];
            }


            if ($this->baseUrl === '') {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' =>
                            'VERIFY_BASE_URL_MISSING',

                        'reference' =>
                            $reference
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Paystack configuration is invalid.',
                    'reference' =>
                        $reference
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Reference
            |--------------------------------------------------------------------------
            */

            if ($reference === '') {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' =>
                            'VERIFY_REFERENCE_MISSING'
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Payment reference is required.'
                ];
            }


            Logger::write(
                'paystack_gateway',
                [
                    'step' =>
                        'VERIFY_START',

                    'reference' =>
                        $reference
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Build Verification URL
            |--------------------------------------------------------------------------
            */

            $url =
                $this->baseUrl
                .
                '/transaction/verify/'
                .
                rawurlencode(
                    $reference
                );


            Logger::write(
                'paystack_gateway',
                [
                    'step' =>
                        'VERIFY_REQUEST',

                    'reference' =>
                        $reference
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Initialize cURL
            |--------------------------------------------------------------------------
            */

            $ch =
                curl_init(
                    $url
                );


            if ($ch === false) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' =>
                            'VERIFY_CURL_INIT_FAILED',

                        'reference' =>
                            $reference
                    ]
                );

                return [
                    'success' => false,
                    'retry' => true,
                    'message' =>
                        'Unable to initialize Paystack verification.',
                    'reference' =>
                        $reference
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Configure cURL
            |--------------------------------------------------------------------------
            */

            curl_setopt_array(
                $ch,
                [

                    CURLOPT_RETURNTRANSFER =>
                        true,

                    CURLOPT_HTTPGET =>
                        true,

                    CURLOPT_FOLLOWLOCATION =>
                        false,

                    CURLOPT_CONNECTTIMEOUT =>
                        10,

                    CURLOPT_TIMEOUT =>
                        60,

                    CURLOPT_HTTPHEADER => [

                        'Authorization: Bearer '
                        .
                        $this->secret,

                        'Content-Type: application/json',

                        'Accept: application/json'
                    ]
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Execute Request
            |--------------------------------------------------------------------------
            */

            $response =
                curl_exec(
                    $ch
                );


            $httpCode =
                (int) curl_getinfo(
                    $ch,
                    CURLINFO_HTTP_CODE
                );


            $curlError =
                curl_error(
                    $ch
                );


            $curlErrno =
                curl_errno(
                    $ch
                );


            curl_close(
                $ch
            );


            Logger::write(
                'paystack_gateway',
                [
                    'step' =>
                        'VERIFY_RESPONSE',

                    'reference' =>
                        $reference,

                    'http_code' =>
                        $httpCode,

                    'curl_errno' =>
                        $curlErrno,

                    'curl_error' =>
                        $curlError,

                    'response' =>
                        is_string($response)
                        ? $response
                        : null
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | cURL Error
            |--------------------------------------------------------------------------
            */

            if ($curlError !== '') {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' =>
                            'VERIFY_CURL_ERROR',

                        'reference' =>
                            $reference,

                        'curl_errno' =>
                            $curlErrno,

                        'message' =>
                            $curlError
                    ]
                );

                return [
                    'success' => false,
                    'retry' => true,
                    'message' =>
                        'Unable to connect to Paystack.',
                    'reference' =>
                        $reference,
                    'http_code' =>
                        $httpCode,
                    'curl_errno' =>
                        $curlErrno
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | HTTP Error
            |--------------------------------------------------------------------------
            */

            if ($httpCode <= 0) {

                return [
                    'success' => false,
                    'retry' => true,
                    'message' =>
                        'No valid response was received from Paystack.',
                    'reference' =>
                        $reference
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Response Must Be String
            |--------------------------------------------------------------------------
            */

            if (!is_string($response)) {

                return [
                    'success' => false,
                    'retry' => true,
                    'message' =>
                        'Invalid response from Paystack.',
                    'reference' =>
                        $reference,
                    'http_code' =>
                        $httpCode
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Decode JSON
            |--------------------------------------------------------------------------
            */

            try {

                $decoded =
                    json_decode(
                        $response,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );

            } catch (Throwable $e) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' =>
                            'VERIFY_JSON_ERROR',

                        'reference' =>
                            $reference,

                        'http_code' =>
                            $httpCode,

                        'message' =>
                            $e->getMessage(),

                        'response' =>
                            $response
                    ]
                );

                return [
                    'success' => false,
                    'retry' =>
                        $httpCode >= 500
                        ||
                        $httpCode === 429,
                    'message' =>
                        'Invalid Paystack verification response.',
                    'reference' =>
                        $reference,
                    'http_code' =>
                        $httpCode
                ];
            }


            if (!is_array($decoded)) {

                return [
                    'success' => false,
                    'retry' => true,
                    'message' =>
                        'Invalid Paystack verification response.',
                    'reference' =>
                        $reference,
                    'http_code' =>
                        $httpCode
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Paystack API Status
            |--------------------------------------------------------------------------
            */

            $apiStatus =
                (bool)(
                    $decoded['status']
                    ?? false
                );


            $apiMessage =
                trim(
                    (string)(
                        $decoded['message']
                        ?? ''
                    )
                );


            if (!$apiStatus) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' =>
                            'VERIFY_PAYSTACK_API_FAILED',

                        'reference' =>
                            $reference,

                        'http_code' =>
                            $httpCode,

                        'message' =>
                            $apiMessage,

                        'raw' =>
                            $decoded
                    ]
                );


                $retry =
                    $httpCode >= 500
                    ||
                    $httpCode === 429;


                return [
                    'success' => false,
                    'retry' => $retry,
                    'message' =>
                        $apiMessage !== ''
                        ? $apiMessage
                        : 'Unable to verify payment.',
                    'reference' =>
                        $reference,
                    'http_code' =>
                        $httpCode,
                    'raw' =>
                        $decoded
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Extract Transaction
            |--------------------------------------------------------------------------
            */

            $data =
                $decoded['data']
                ?? null;


            if (!is_array($data)) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' =>
                            'VERIFY_TRANSACTION_DATA_INVALID',

                        'reference' =>
                            $reference,

                        'http_code' =>
                            $httpCode
                    ]
                );

                return [
                    'success' => false,
                    'retry' => true,
                    'message' =>
                        'Invalid payment verification data.',
                    'reference' =>
                        $reference,
                    'http_code' =>
                        $httpCode,
                    'raw' =>
                        $decoded
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Verified Paystack Reference
            |--------------------------------------------------------------------------
            */

            $verifiedReference =
                trim(
                    (string)(
                        $data['reference']
                        ?? ''
                    )
                );


            if ($verifiedReference === '') {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' =>
                            'VERIFY_REFERENCE_NOT_RETURNED',

                        'requested_reference' =>
                            $reference,

                        'data' =>
                            $data
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Paystack did not return a transaction reference.',
                    'reference' =>
                        $reference,
                    'data' =>
                        $data,
                    'raw' =>
                        $decoded
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Reference Integrity
            |--------------------------------------------------------------------------
            */

            if (
                !hash_equals(
                    strtoupper($reference),
                    strtoupper($verifiedReference)
                )
            ) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' =>
                            'VERIFY_REFERENCE_MISMATCH',

                        'requested_reference' =>
                            $reference,

                        'verified_reference' =>
                            $verifiedReference
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Paystack transaction reference mismatch.',
                    'reference' =>
                        $reference,
                    'verified_reference' =>
                        $verifiedReference,
                    'data' =>
                        $data,
                    'raw' =>
                        $decoded
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Transaction Status
            |--------------------------------------------------------------------------
            */

            $paymentStatus =
                strtolower(
                    trim(
                        (string)(
                            $data['status']
                            ?? ''
                        )
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | Amount
            |--------------------------------------------------------------------------
            */

            $amountKobo =
                (int)(
                    $data['amount']
                    ?? 0
                );


            $amountNgn =
                $amountKobo > 0
                ? $amountKobo / 100
                : 0.0;


            /*
            |--------------------------------------------------------------------------
            | Currency
            |--------------------------------------------------------------------------
            */

            $currency =
                strtoupper(
                    trim(
                        (string)(
                            $data['currency']
                            ?? ''
                        )
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | Customer
            |--------------------------------------------------------------------------
            */

            $customer =
                $data['customer']
                ?? [];


            if (!is_array($customer)) {

                $customer = [];
            }


            /*
            |--------------------------------------------------------------------------
            | Metadata
            |--------------------------------------------------------------------------
            */

            $metadata =
                $data['metadata']
                ?? [];


            if (!is_array($metadata)) {

                $metadata = [];
            }


            /*
            |--------------------------------------------------------------------------
            | Log Parsed Transaction
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'paystack_gateway',
                [
                    'step' =>
                        'VERIFY_TRANSACTION_PARSED',

                    'reference' =>
                        $verifiedReference,

                    'status' =>
                        $paymentStatus,

                    'amount_kobo' =>
                        $amountKobo,

                    'amount_ngn' =>
                        $amountNgn,

                    'currency' =>
                        $currency,

                    'metadata' =>
                        $metadata
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Payment Not Successful
            |--------------------------------------------------------------------------
            */

            if (
                $paymentStatus !== 'success'
            ) {

                Logger::write(
                    'paystack_gateway',
                    [
                        'step' =>
                            'PAYMENT_NOT_SUCCESSFUL',

                        'reference' =>
                            $verifiedReference,

                        'status' =>
                            $paymentStatus,

                        'amount_kobo' =>
                            $amountKobo
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Payment not successful.',
                    'reference' =>
                        $verifiedReference,
                    'status' =>
                        $paymentStatus,
                    'amount' =>
                        $amountNgn,
                    'amount_kobo' =>
                        $amountKobo,
                    'currency' =>
                        $currency,
                    'metadata' =>
                        $metadata,
                    'customer' =>
                        $customer,
                    'data' =>
                        $data,
                    'raw' =>
                        $decoded
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Successful Payment Must Have Amount
            |--------------------------------------------------------------------------
            */

            if ($amountKobo <= 0) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step' =>
                            'VERIFY_SUCCESS_ZERO_AMOUNT',

                        'reference' =>
                            $verifiedReference
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Verified payment has an invalid amount.',
                    'reference' =>
                        $verifiedReference,
                    'status' =>
                        $paymentStatus,
                    'amount' =>
                        $amountNgn,
                    'amount_kobo' =>
                        $amountKobo,
                    'currency' =>
                        $currency,
                    'metadata' =>
                        $metadata,
                    'data' =>
                        $data,
                    'raw' =>
                        $decoded
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Successful Verification
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'paystack_gateway',
                [
                    'step' =>
                        'VERIFY_SUCCESS',

                    'reference' =>
                        $verifiedReference,

                    'status' =>
                        $paymentStatus,

                    'amount_ngn' =>
                        $amountNgn,

                    'amount_kobo' =>
                        $amountKobo,

                    'currency' =>
                        $currency
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Return Normalized Result
            |--------------------------------------------------------------------------
            */

            return [

                'success' =>
                    true,

                'retry' =>
                    false,

                'reference' =>
                    $verifiedReference,

                'status' =>
                    $paymentStatus,

                'amount' =>
                    $amountNgn,

                'amount_kobo' =>
                    $amountKobo,

                'currency' =>
                    $currency,

                'metadata' =>
                    $metadata,

                'customer' =>
                    $customer,

                'data' =>
                    $data,

                'raw' =>
                    $decoded
            ];


        } catch (Throwable $e) {

            Logger::write(
                'paystack_gateway_error',
                [
                    'step' =>
                        'VERIFY_EXCEPTION',

                    'reference' =>
                        $reference,

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),

                    'trace' =>
                        $e->getTraceAsString()
                ]
            );

            return [

                'success' =>
                    false,

                'retry' =>
                    true,

                'message' =>
                    'Payment verification failed.',

                'reference' =>
                    $reference
            ];
        }
    }
}