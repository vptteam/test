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
     */
    public function verify(
        string $reference
    ): array {

        try {

            $reference = trim(
                $reference
            );


            Logger::write(
                'paystack_gateway',
                [
                    'step'      => 'VERIFY_START',
                    'reference' => $reference
                ]
            );


            if ($reference === '') {

                return [
                    'success' => false,
                    'message' => 'Payment reference is required.'
                ];
            }


            $url =
                rtrim(
                    PAYSTACK_BASE_URL,
                    '/'
                ) .
                '/transaction/verify/' .
                rawurlencode($reference);


            $ch = curl_init(
                $url
            );


            if ($ch === false) {

                return [
                    'success' => false,
                    'message' => 'Unable to initialize verification connection.'
                ];
            }


            curl_setopt_array(
                $ch,
                [
                    CURLOPT_RETURNTRANSFER => true,

                    CURLOPT_CONNECTTIMEOUT => 10,

                    CURLOPT_TIMEOUT => 60,

                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' .
                            $this->secret,

                        'Content-Type: application/json',

                        'Accept: application/json'
                    ]
                ]
            );


            $response = curl_exec(
                $ch
            );


            $http = curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );


            $error = curl_error(
                $ch
            );


            curl_close(
                $ch
            );


            Logger::write(
                'paystack_gateway',
                [
                    'step'      => 'VERIFY_RESPONSE',
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

                return [
                    'success' => false,
                    'message' => 'Invalid Paystack verification response.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Paystack API Request Failed
            |--------------------------------------------------------------------------
            */

            if (
                !($decoded['status'] ?? false)
            ) {

                return [
                    'success' => false,

                    'message' =>
                        $decoded['message']
                        ??
                        'Unable to verify payment.',

                    'raw' => $decoded
                ];
            }


            $data =
                $decoded['data']
                ?? null;


            if (!is_array($data)) {

                return [
                    'success' => false,
                    'message' => 'Invalid payment verification data.',
                    'raw'     => $decoded
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Payment Status
            |--------------------------------------------------------------------------
            */

            $paymentStatus = strtolower(
                trim(
                    (string)(
                        $data['status']
                        ?? ''
                    )
                )
            );


            /*
            |--------------------------------------------------------------------------
            | Paystack Reference
            |--------------------------------------------------------------------------
            */

            $verifiedReference = trim(
                (string)(
                    $data['reference']
                    ?? ''
                )
            );


            /*
            |--------------------------------------------------------------------------
            | Amount
            |--------------------------------------------------------------------------
            |
            | Paystack returns amount in kobo.
            |
            */

            $amountKobo = (int)(
                $data['amount']
                ?? 0
            );


            $amountNgn =
                $amountKobo / 100;


            /*
            |--------------------------------------------------------------------------
            | Currency
            |--------------------------------------------------------------------------
            */

            $currency = strtoupper(
                trim(
                    (string)(
                        $data['currency']
                        ?? ''
                    )
                )
            );


            /*
            |--------------------------------------------------------------------------
            | Successful Payment
            |--------------------------------------------------------------------------
            */

            if (
                $paymentStatus !== 'success'
            ) {

                Logger::write(
                    'paystack_gateway',
                    [
                        'step'            => 'PAYMENT_NOT_SUCCESSFUL',
                        'reference'       => $reference,
                        'verified_status' => $paymentStatus,
                        'amount_kobo'     => $amountKobo
                    ]
                );


                return [
                    'success' => false,

                    'message' =>
                        'Payment not successful.',

                    'status' =>
                        $paymentStatus,

                    'amount' =>
                        $amountNgn,

                    'amount_kobo' =>
                        $amountKobo,

                    'currency' =>
                        $currency,

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
                    'step'      => 'VERIFY_SUCCESS',
                    'reference' => $verifiedReference,
                    'amount'    => $amountNgn,
                    'currency'  => $currency
                ]
            );


            return [
                'success' => true,

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

                'data' =>
                    $data,

                'raw' =>
                    $decoded
            ];


        } catch (Throwable $e) {

            Logger::write(
                'paystack_gateway_error',
                [
                    'step'      => 'VERIFY_EXCEPTION',
                    'reference' => $reference,
                    'message'   => $e->getMessage(),
                    'line'      => $e->getLine(),
                    'file'      => $e->getFile()
                ]
            );


            return [
                'success' => false,
                'message' => 'Verification failed.'
            ];
        }
    }
}