<?php

declare(strict_types=1);

namespace Services\Payments;

use Core\Logger;
use Throwable;

class PaystackGateway
{
    protected string $secret;

    protected string $endpoint;

    public function __construct()
    {
        $this->secret = PAYSTACK_SECRET_KEY;

        $this->endpoint =
            rtrim(PAYSTACK_BASE_URL, '/') .
            '/transaction/initialize';

        Logger::write(
            'paystack_gateway',
            [
                'step' => 'CONSTRUCTOR'
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * Initialize Payment
     * ---------------------------------------------------------
     */
    public function initialize(
        int $amount,
        string $email,
        string $reference,
        string $callback,
        array $metadata = []
    ): array {

        try {

            Logger::write(
                'paystack_gateway',
                [
                    'step'      => 'INITIALIZE_START',
                    'amount'    => $amount,
                    'email'     => $email,
                    'reference' => $reference,
                    'callback'  => $callback,
                    'metadata'  => $metadata
                ]
            );


            if ($amount <= 0) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step'      => 'INVALID_AMOUNT',
                        'amount'    => $amount,
                        'reference' => $reference
                    ]
                );

                return [
                    'success' => false,
                    'message' => 'Invalid payment amount.'
                ];
            }


            if (
                empty($email)
                || !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step'      => 'INVALID_EMAIL',
                        'email'     => $email,
                        'reference' => $reference
                    ]
                );

                return [
                    'success' => false,
                    'message' => 'Invalid payment email.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Paystack Amount
            |--------------------------------------------------------------------------
            |
            | Internal application amounts are NGN.
            |
            | Paystack expects kobo.
            |
            */

            $amountKobo = $amount * 100;


            $payload = [
                'amount' => $amountKobo,

                'email' => $email,

                'reference' => $reference,

                'currency' => 'NGN',

                'callback_url' => $callback,

                'metadata' => $metadata
            ];


            Logger::write(
                'paystack_gateway',
                [
                    'step'       => 'INITIALIZE_PAYLOAD',
                    'amount_ngn' => $amount,
                    'amount_kobo'=> $amountKobo,
                    'reference'  => $reference,
                    'metadata'   => $metadata
                ]
            );


            $ch = curl_init(
                $this->endpoint
            );


            if ($ch === false) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step'      => 'CURL_INIT_FAILED',
                        'reference' => $reference
                    ]
                );

                return [
                    'success' => false,
                    'message' => 'Unable to initialize payment connection.'
                ];
            }


            curl_setopt_array(
                $ch,
                [
                    CURLOPT_RETURNTRANSFER => true,

                    CURLOPT_POST => true,

                    CURLOPT_POSTFIELDS =>
                        json_encode(
                            $payload,
                            JSON_UNESCAPED_SLASHES
                        ),

                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' .
                            $this->secret,

                        'Content-Type: application/json',

                        'Accept: application/json'
                    ],

                    CURLOPT_CONNECTTIMEOUT => 10,

                    CURLOPT_TIMEOUT => 60
                ]
            );


            $response = curl_exec($ch);


            $http = curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );


            $error = curl_error($ch);


            curl_close($ch);


            Logger::write(
                'paystack_gateway',
                [
                    'step'      => 'INITIALIZE_RESPONSE',
                    'http'      => $http,
                    'reference' => $reference,
                    'error'     => $error,
                    'response'  => $response
                ]
            );


            if ($error) {

                return [
                    'success' => false,
                    'message' => $error
                ];
            }


            if (!is_string($response)) {

                return [
                    'success' => false,
                    'message' => 'Invalid response from Paystack.'
                ];
            }


            $decoded = json_decode(
                $response,
                true
            );


            if (!is_array($decoded)) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step'      => 'INVALID_JSON_RESPONSE',
                        'reference' => $reference,
                        'response'  => $response
                    ]
                );

                return [
                    'success' => false,
                    'message' => 'Invalid response from Paystack.'
                ];
            }


            if (
                !($decoded['status'] ?? false)
            ) {

                return [
                    'success' => false,

                    'message' =>
                        $decoded['message']
                        ??
                        'Payment initialization failed.',

                    'raw' => $decoded
                ];
            }


            $data = $decoded['data'] ?? [];


            if (!is_array($data)) {

                return [
                    'success' => false,
                    'message' => 'Invalid payment response.',
                    'raw'     => $decoded
                ];
            }


            $authorizationUrl =
                $data['authorization_url']
                ?? null;


            $accessCode =
                $data['access_code']
                ?? null;


            $paystackReference =
                $data['reference']
                ?? $reference;


            if (empty($authorizationUrl)) {

                Logger::write(
                    'paystack_gateway_error',
                    [
                        'step'      => 'AUTHORIZATION_URL_MISSING',
                        'reference' => $reference,
                        'response'  => $decoded
                    ]
                );

                return [
                    'success' => false,

                    'message' =>
                        'Paystack did not return a payment link.',

                    'raw' => $decoded
                ];
            }


            Logger::write(
                'paystack_gateway',
                [
                    'step'      => 'INITIALIZE_SUCCESS',
                    'reference' => $paystackReference
                ]
            );


            return [
                'success' => true,

                'authorization_url' =>
                    $authorizationUrl,

                'access_code' =>
                    $accessCode,

                'reference' =>
                    $paystackReference,

                'raw' =>
                    $decoded
            ];


        } catch (Throwable $e) {

            Logger::write(
                'paystack_gateway_error',
                [
                    'step'    => 'INITIALIZE_EXCEPTION',
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine(),
                    'file'    => $e->getFile()
                ]
            );


            return [
                'success' => false,
                'message' => 'Paystack error.'
            ];
        }
    }


    /**
 * ---------------------------------------------------------
 * Verify Payment
 * ---------------------------------------------------------
 *
 * Verifies a Paystack transaction using its payment reference.
 *
 * IMPORTANT:
 *
 * The reference passed here must be the actual Paystack
 * transaction reference, for example:
 *
 *     ESC-SDM-000037-A1B2C3D4
 *
 * It is NOT the escrow public reference:
 *
 *     SDM-000037
 *
 * Paystack returns amount in kobo. This method exposes both
 * amount_kobo and amount in NGN.
 *
 * ---------------------------------------------------------
 */
public function verify(
    string $reference
): array {

    $reference = trim($reference);

    try {

        /*
        |--------------------------------------------------------------------------
        | Normalize Reference
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
        | Build Paystack Verification URL
        |--------------------------------------------------------------------------
        */

        $baseUrl =
            rtrim(
                PAYSTACK_BASE_URL,
                '/'
            );


        if ($baseUrl === '') {

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
                'message' =>
                    'Paystack configuration is invalid.'
            ];
        }


        $url =
            $baseUrl
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
                    $reference,

                'url' =>
                    $url
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
                'message' =>
                    'Unable to initialize Paystack verification.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Configure Request
        |--------------------------------------------------------------------------
        */

        curl_setopt_array(
            $ch,
            [
                CURLOPT_RETURNTRANSFER =>
                    true,

                CURLOPT_FOLLOWLOCATION =>
                    false,

                CURLOPT_CONNECTTIMEOUT =>
                    10,

                CURLOPT_TIMEOUT =>
                    60,

                CURLOPT_HTTPGET =>
                    true,

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
            (int)curl_getinfo(
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
        | Log Raw Response
        |--------------------------------------------------------------------------
        */

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
        | cURL Failure
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
        | Invalid HTTP Response
        |--------------------------------------------------------------------------
        */

        if ($httpCode <= 0) {

            Logger::write(
                'paystack_gateway_error',
                [
                    'step' =>
                        'VERIFY_HTTP_CODE_MISSING',

                    'reference' =>
                        $reference
                ]
            );

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
        | Invalid Response Body
        |--------------------------------------------------------------------------
        */

        if (!is_string($response)) {

            Logger::write(
                'paystack_gateway_error',
                [
                    'step' =>
                        'VERIFY_RESPONSE_NOT_STRING',

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

        $decoded =
            json_decode(
                $response,
                true
            );


        if (
            !is_array($decoded)
        ) {

            Logger::write(
                'paystack_gateway_error',
                [
                    'step' =>
                        'VERIFY_JSON_DECODE_FAILED',

                    'reference' =>
                        $reference,

                    'http_code' =>
                        $httpCode,

                    'json_error' =>
                        json_last_error_msg(),

                    'response' =>
                        $response
                ]
            );

            return [
                'success' => false,

                'retry' =>
                    $httpCode >= 500
                    || $httpCode === 429,

                'message' =>
                    'Invalid Paystack verification response.',

                'reference' =>
                    $reference,

                'http_code' =>
                    $httpCode,

                'raw_response' =>
                    $response
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Extract Paystack API Status
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


        /*
        |--------------------------------------------------------------------------
        | Paystack API Request Failed
        |--------------------------------------------------------------------------
        */

        if (
            !$apiStatus
        ) {

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


            /*
             * 5xx and 429 responses may be transient.
             */
            $retry =
                $httpCode >= 500
                ||
                $httpCode === 429;


            return [
                'success' => false,

                'retry' =>
                    $retry,

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
        | Extract Transaction Data
        |--------------------------------------------------------------------------
        */

        $data =
            $decoded['data']
            ?? null;


        if (
            !is_array($data)
        ) {

            Logger::write(
                'paystack_gateway_error',
                [
                    'step' =>
                        'VERIFY_TRANSACTION_DATA_INVALID',

                    'reference' =>
                        $reference,

                    'http_code' =>
                        $httpCode,

                    'raw' =>
                        $decoded
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
        | Extract Verified Paystack Reference
        |--------------------------------------------------------------------------
        */

        $verifiedReference =
            trim(
                (string)(
                    $data['reference']
                    ?? ''
                )
            );


        if (
            $verifiedReference === ''
        ) {

            Logger::write(
                'paystack_gateway_error',
                [
                    'step' =>
                        'VERIFY_REFERENCE_NOT_RETURNED',

                    'requested_reference' =>
                        $reference,

                    'http_code' =>
                        $httpCode,

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

                'http_code' =>
                    $httpCode,

                'data' =>
                    $data,

                'raw' =>
                    $decoded
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Verify Reference Integrity
        |--------------------------------------------------------------------------
        |
        | Never trust a response that contains a different reference
        | from the transaction we requested.
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
                        $verifiedReference,

                    'http_code' =>
                        $httpCode
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

                'http_code' =>
                    $httpCode,

                'data' =>
                    $data,

                'raw' =>
                    $decoded
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Payment Status
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
        |
        | Paystack returns the transaction amount in kobo.
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


        if (
            !is_array($customer)
        ) {
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


        /*
         * Paystack can sometimes return metadata as something other
         * than an array depending on the request/response structure.
         */
        if (
            !is_array($metadata)
        ) {
            $metadata = [];
        }


        /*
        |--------------------------------------------------------------------------
        | Log Verified Transaction
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
        | Payment Was Not Successful
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
                        $amountKobo,

                    'amount_ngn' =>
                        $amountNgn
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
        | Successful Payment Must Have A Positive Amount
        |--------------------------------------------------------------------------
        */

        if (
            $amountKobo <= 0
        ) {

            Logger::write(
                'paystack_gateway_error',
                [
                    'step' =>
                        'VERIFY_SUCCESSFUL_PAYMENT_ZERO_AMOUNT',

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

                'amount' =>
                    $amountNgn,

                'amount_kobo' =>
                    $amountKobo,

                'currency' =>
                    $currency
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Return Normalized Verification Result
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

                'line' =>
                    $e->getLine(),

                'file' =>
                    $e->getFile(),

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