<?php

declare(strict_types=1);

namespace Controllers;

use Core\Logger;
use Services\Escrow\EscrowApiService;
use Throwable;
use Services\Payments\PaystackEscrowPaymentService;

class EscrowApiController
{
    protected EscrowApiService $service;

    /**
     * ---------------------------------------------------------
     * Constructor
     * ---------------------------------------------------------
     */
    public function __construct()
    {
        $this->service =
            new EscrowApiService();

        Logger::write(
            'escrow_api_controller',
            [
                'step' => 'CONSTRUCTOR'
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * VERIFY
     * ---------------------------------------------------------
     *
     * Endpoint:
     *
     * POST /api/escrow/verify
     *
     * Expected:
     *
     * {
     *     "reference": "SDM-000033"
     * }
     *
     * ---------------------------------------------------------
     */
    public function verify(): void
    {
        try {

            Logger::write(
                'escrow_api_controller',
                [
                    'step' => 'VERIFY_REQUEST',
                    'method' =>
                        $_SERVER['REQUEST_METHOD']
                        ?? null,
                    'uri' =>
                        $_SERVER['REQUEST_URI']
                        ?? null
                ]
            );


            $input =
                $this->input();


            $reference =
                trim(
                    (string)(
                        $input['reference']
                        ??
                        $input['escrow_reference']
                        ??
                        ''
                    )
                );


            if ($reference === '') {

                $this->json(
                    [
                        'success' => false,
                        'message' =>
                            'Escrow reference is required.'
                    ],
                    422
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Delegate To Existing Service
            |--------------------------------------------------------------------------
            */

            $result =
                $this->service->verify(
                    $reference
                );


            Logger::write(
                'escrow_api_controller',
                [
                    'step' =>
                        'VERIFY_SERVICE_RESULT',
                    'reference' =>
                        $reference,
                    'result' =>
                        $result
                ]
            );


            $status =
                ($result['success'] ?? false)
                ? 200
                : 404;


            $this->json(
                $result,
                $status
            );

        }
        catch (Throwable $e) {

            Logger::write(
                'escrow_api_controller_error',
                [
                    'step' =>
                        'VERIFY_EXCEPTION',
                    'message' =>
                        $e->getMessage(),
                    'file' =>
                        $e->getFile(),
                    'line' =>
                        $e->getLine()
                ]
            );


            $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Unable to verify escrow.'
                ],
                500
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * RELEASE
     * ---------------------------------------------------------
     *
     * IMPORTANT:
     *
     * "Release" does NOT directly pay the seller.
     *
     * It means:
     *
     * Buyer confirms receipt.
     *
     * The existing escrow confirmation workflow then continues.
     *
     * Endpoint:
     *
     * POST /api/escrow/release
     *
     * Expected:
     *
     * {
     *     "reference": "SDM-000033",
     *     "phone": "08012345678"
     * }
     *
     * ---------------------------------------------------------
     */
    public function release(): void
    {
        try {

            Logger::write(
                'escrow_api_controller',
                [
                    'step' => 'RELEASE_REQUEST',
                    'method' =>
                        $_SERVER['REQUEST_METHOD']
                        ?? null,
                    'uri' =>
                        $_SERVER['REQUEST_URI']
                        ?? null
                ]
            );


            $input =
                $this->input();


            $reference =
                trim(
                    (string)(
                        $input['reference']
                        ??
                        $input['escrow_reference']
                        ??
                        ''
                    )
                );


            $phone =
                trim(
                    (string)(
                        $input['phone']
                        ??
                        $input['buyer_phone']
                        ??
                        ''
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | Validate Reference
            |--------------------------------------------------------------------------
            */

            if ($reference === '') {

                $this->json(
                    [
                        'success' => false,
                        'message' =>
                            'Escrow reference is required.'
                    ],
                    422
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Phone
            |--------------------------------------------------------------------------
            */

            if ($phone === '') {

                $this->json(
                    [
                        'success' => false,
                        'message' =>
                            'Buyer phone number is required.'
                    ],
                    422
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Delegate To Existing API Service
            |--------------------------------------------------------------------------
            */

            $result =
                $this->service->confirmReceipt(
                    $reference,
                    $phone
                );


            Logger::write(
                'escrow_api_controller',
                [
                    'step' =>
                        'RELEASE_SERVICE_RESULT',
                    'reference' =>
                        $reference,
                    'phone' =>
                        $phone,
                    'result' =>
                        $result
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Determine HTTP Status
            |--------------------------------------------------------------------------
            */

            if (
                $result['success']
                ?? false
            ) {

                $status = 200;

            }
            else {

                /*
                |--------------------------------------------------------------------------
                | Authorization Failure
                |--------------------------------------------------------------------------
                */

                $message =
                    strtolower(
                        (string)(
                            $result['message']
                            ?? ''
                        )
                    );


                if (
                    str_contains(
                        $message,
                        'not authorized'
                    )
                    ||
                    str_contains(
                        $message,
                        'not registered'
                    )
                ) {

                    $status = 403;

                }
                elseif (
                    str_contains(
                        $message,
                        'not found'
                    )
                ) {

                    $status = 404;

                }
                elseif (
                    str_contains(
                        $message,
                        'required'
                    )
                ) {

                    $status = 422;

                }
                else {

                    $status = 400;

                }

            }


            $this->json(
                $result,
                $status
            );

        }
        catch (Throwable $e) {

            Logger::write(
                'escrow_api_controller_error',
                [
                    'step' =>
                        'RELEASE_EXCEPTION',
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


            $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Unable to release escrow.'
                ],
                500
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * Read JSON / Form Input
     * ---------------------------------------------------------
     */
    protected function input(): array
    {
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


        /*
        |--------------------------------------------------------------------------
        | Fallback To POST
        |--------------------------------------------------------------------------
        */

        if (
            !empty($_POST)
        ) {

            return $_POST;
        }


        /*
        |--------------------------------------------------------------------------
        | Empty Input
        |--------------------------------------------------------------------------
        */

        return [];
    }


    /**
     * ---------------------------------------------------------
     * JSON Response
     * ---------------------------------------------------------
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


        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
            |
            JSON_UNESCAPED_SLASHES
        );
    }
    
    /**
 * ---------------------------------------------------------
 * PAYMENT
 * ---------------------------------------------------------
 *
 * Initialize an escrow payment through Paystack.
 *
 * Endpoint:
 *
 * POST /api/escrow/payment
 *
 * Expected:
 *
 * {
 *     "reference": "SDM-000033",
 *     "email": "buyer@example.com"
 * }
 *
 * IMPORTANT:
 *
 * The client does NOT supply the payment amount.
 *
 * The amount is loaded from the existing escrow record by
 * PaystackEscrowPaymentService.
 *
 * ---------------------------------------------------------
 */
public function payment(): void
{
    try {

        Logger::write(
            'escrow_api_controller',
            [
                'step' => 'PAYMENT_REQUEST',
                'method' =>
                    $_SERVER['REQUEST_METHOD']
                    ?? null,
                'uri' =>
                    $_SERVER['REQUEST_URI']
                    ?? null
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Read Request
        |--------------------------------------------------------------------------
        */

        $input =
            $this->input();


        Logger::write(
            'escrow_api_controller',
            [
                'step' => 'PAYMENT_INPUT',
                'input' => $input
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Reference
        |--------------------------------------------------------------------------
        */

        $reference =
            strtoupper(
                trim(
                    (string)(
                        $input['reference']
                        ??
                        $input['escrow_reference']
                        ??
                        ''
                    )
                )
            );


        /*
        |--------------------------------------------------------------------------
        | Email
        |--------------------------------------------------------------------------
        */

        $email =
            trim(
                (string)(
                    $input['email']
                    ??
                    $input['buyer_email']
                    ??
                    ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | Validate Reference
        |--------------------------------------------------------------------------
        */

        if ($reference === '') {

            Logger::write(
                'escrow_api_controller_error',
                [
                    'step' =>
                        'PAYMENT_REFERENCE_MISSING'
                ]
            );


            $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Escrow reference is required.'
                ],
                422
            );

            return;
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
                'escrow_api_controller_error',
                [
                    'step' =>
                        'PAYMENT_EMAIL_INVALID',
                    'reference' =>
                        $reference,
                    'email' =>
                        $email
                ]
            );


            $this->json(
                [
                    'success' => false,
                    'message' =>
                        'A valid email address is required.'
                ],
                422
            );

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Callback URL
        |--------------------------------------------------------------------------
        |
        | Optional. The existing payment service can use its configured
        | callback when this is omitted.
        |
        */

        $callbackUrl =
            trim(
                (string)(
                    $input['callback_url']
                    ??
                    ''
                )
            );


        if ($callbackUrl === '') {

            $callbackUrl = null;
        }


        /*
        |--------------------------------------------------------------------------
        | Log Request
        |--------------------------------------------------------------------------
        */

        Logger::write(
            'escrow_api_controller',
            [
                'step' =>
                    'PAYMENT_INITIALIZATION_START',
                'reference' =>
                    $reference,
                'email' =>
                    $email,
                'callback_url' =>
                    $callbackUrl
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Existing Escrow Paystack Service
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | We deliberately do NOT accept amount from the client.
        |
        | PaystackEscrowPaymentService loads the escrow record and
        | determines the authoritative amount.
        |
        */

        $paymentService =
            new PaystackEscrowPaymentService();


        $result =
            $paymentService->initialize(
                $reference,
                $email,
                $callbackUrl
            );


        Logger::write(
            'escrow_api_controller',
            [
                'step' =>
                    'PAYMENT_INITIALIZATION_RESULT',
                'reference' =>
                    $reference,
                'result' =>
                    $result
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Determine HTTP Status
        |--------------------------------------------------------------------------
        */

        if (
            !is_array($result)
        ) {

            $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Unable to initialize escrow payment.'
                ],
                500
            );

            return;
        }


        if (
            ($result['success'] ?? false)
        ) {

            $this->json(
                $result,
                200
            );

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Determine Failure Status
        |--------------------------------------------------------------------------
        */

        $message =
            strtolower(
                trim(
                    (string)(
                        $result['message']
                        ??
                        ''
                    )
                )
            );


        if (
            str_contains(
                $message,
                'required'
            )
        ) {

            $status = 422;

        }
        elseif (
            str_contains(
                $message,
                'not found'
            )
        ) {

            $status = 404;

        }
        elseif (
            str_contains(
                $message,
                'not authorized'
            )
            ||
            str_contains(
                $message,
                'unauthorized'
            )
        ) {

            $status = 403;

        }
        elseif (
            str_contains(
                $message,
                'already'
            )
        ) {

            $status = 409;

        }
        else {

            $status = 400;
        }


        $this->json(
            $result,
            $status
        );

    }
    catch (Throwable $e) {

        Logger::write(
            'escrow_api_controller_error',
            [
                'step' =>
                    'PAYMENT_EXCEPTION',

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


        $this->json(
            [
                'success' => false,
                'message' =>
                    'Unable to initialize escrow payment.'
            ],
            500
        );
    }
}
}
?>
