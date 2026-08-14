<?php

declare(strict_types=1);

namespace Controllers;

use Core\Logger;
use Services\Escrow\EscrowApiService;
use Services\Escrow\PaystackEscrowPaymentService;
use Throwable;

class EscrowApiController
{
    protected EscrowApiService $service;

    protected PaystackEscrowPaymentService $paymentService;


    /**
     * ---------------------------------------------------------
     * Constructor
     * ---------------------------------------------------------
     */
    public function __construct()
    {
        $this->service =
            new EscrowApiService();

        $this->paymentService =
            new PaystackEscrowPaymentService();

        Logger::write(
            'escrow_api_controller',
            [
                'step' => 'CONSTRUCTOR',
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * VERIFY ESCROW
     * ---------------------------------------------------------
     *
     * POST /api/escrow/verify
     *
     * Request:
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
                    'step' =>
                        'VERIFY_REQUEST',

                    'method' =>
                        $_SERVER['REQUEST_METHOD']
                        ?? null,

                    'uri' =>
                        $_SERVER['REQUEST_URI']
                        ?? null,
                ]
            );


            $input =
                $this->input();


            $reference =
                $this->normalizeReference(
                    $input
                );


            Logger::write(
                'escrow_api_controller',
                [
                    'step' =>
                        'VERIFY_INPUT',

                    'reference' =>
                        $reference,
                ]
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
                            'Escrow reference is required.',
                    ],
                    422
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Verify Escrow
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
                        $result,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Response Status
            |--------------------------------------------------------------------------
            */

            $status =
                $this->statusForResult(
                    $result,
                    404
                );


            $this->json(
                $result,
                $status
            );

        }
        catch (Throwable $e) {

            $this->handleException(
                'VERIFY_EXCEPTION',
                $e,
                'Unable to verify escrow.'
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * PAY ESCROW
     * ---------------------------------------------------------
     *
     * POST /api/escrow/payment
     *
     * Request:
     *
     * {
     *     "reference": "SDM-000033",
     *     "email": "buyer@example.com"
     * }
     *
     * The client NEVER supplies the payment amount.
     *
     * The amount is loaded from the authoritative escrow record.
     *
     * ---------------------------------------------------------
     */
    public function payment(): void
    {
        try {

            Logger::write(
                'escrow_api_controller',
                [
                    'step' =>
                        'PAYMENT_REQUEST',

                    'method' =>
                        $_SERVER['REQUEST_METHOD']
                        ?? null,

                    'uri' =>
                        $_SERVER['REQUEST_URI']
                        ?? null,
                ]
            );


            $input =
                $this->input();


            $reference =
                $this->normalizeReference(
                    $input
                );


            $email =
                $this->normalizeEmail(
                    $input
                );


            Logger::write(
                'escrow_api_controller',
                [
                    'step' =>
                        'PAYMENT_INPUT',

                    'reference' =>
                        $reference,

                    'email' =>
                        $email,
                ]
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
                            'Escrow reference is required.',
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
                    ]
                );


                $this->json(
                    [
                        'success' => false,

                        'message' =>
                            'A valid email address is required.',
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
            | The API client does not control the internal Paystack
            | callback URL.
            |
            | PaystackEscrowPaymentService will use the configured
            | escrow callback when no callback is explicitly supplied.
            |
            | We therefore deliberately do not trust arbitrary callback
            | URLs from the API request.
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
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Initialize Payment
            |--------------------------------------------------------------------------
            */

            $result =
                $this->paymentService->initialize(
                    $reference,
                    $email,
                    null
                );


            Logger::write(
                'escrow_api_controller',
                [
                    'step' =>
                        'PAYMENT_INITIALIZATION_RESULT',

                    'reference' =>
                        $reference,

                    'success' =>
                        $result['success']
                        ?? false,

                    'payment_reference' =>
                        $result['payment_reference']
                        ?? null,

                    'message' =>
                        $result['message']
                        ?? null,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Normalize Invalid Service Response
            |--------------------------------------------------------------------------
            */

            if (!is_array($result)) {

                $this->json(
                    [
                        'success' => false,

                        'message' =>
                            'Unable to initialize escrow payment.',
                    ],
                    500
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Successful Initialization
            |--------------------------------------------------------------------------
            */

            if (
                ($result['success'] ?? false) === true
            ) {

                $this->json(
                    $result,
                    200
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Failed Initialization
            |--------------------------------------------------------------------------
            */

            $status =
                $this->statusForResult(
                    $result,
                    400
                );


            $this->json(
                $result,
                $status
            );

        }
        catch (Throwable $e) {

            $this->handleException(
                'PAYMENT_EXCEPTION',
                $e,
                'Unable to initialize escrow payment.'
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * RELEASE / CONFIRM RECEIPT
     * ---------------------------------------------------------
     *
     * POST /api/escrow/release
     *
     * Request:
     *
     * {
     *     "reference": "SDM-000033",
     *     "phone": "08012345678"
     * }
     *
     * IMPORTANT:
     *
     * This endpoint does not blindly release money.
     *
     * It confirms buyer receipt through the existing
     * EscrowConfirmationService workflow.
     *
     * ---------------------------------------------------------
     */
    public function release(): void
    {
        try {

            Logger::write(
                'escrow_api_controller',
                [
                    'step' =>
                        'RELEASE_REQUEST',

                    'method' =>
                        $_SERVER['REQUEST_METHOD']
                        ?? null,

                    'uri' =>
                        $_SERVER['REQUEST_URI']
                        ?? null,
                ]
            );


            $input =
                $this->input();


            $reference =
                $this->normalizeReference(
                    $input
                );


            $phone =
                trim(
                    (string)(
                        $input['phone']
                        ??
                        $input['phone_number']
                        ??
                        $input['buyer_phone']
                        ??
                        ''
                    )
                );


            Logger::write(
                'escrow_api_controller',
                [
                    'step' =>
                        'RELEASE_INPUT',

                    'reference' =>
                        $reference,

                    'phone' =>
                        $phone,
                ]
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
                            'Escrow reference is required.',
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
                            'Buyer phone number is required.',
                    ],
                    422
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Confirm Receipt
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

                    'result' =>
                        $result,
                ]
            );


            $status =
                $this->statusForResult(
                    $result,
                    400
                );


            $this->json(
                $result,
                $status
            );

        }
        catch (Throwable $e) {

            $this->handleException(
                'RELEASE_EXCEPTION',
                $e,
                'Unable to release escrow.'
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * PAYMENT STATUS
     * ---------------------------------------------------------
     *
     * GET /api/escrow/payment/status
     *
     * POST /api/escrow/payment/status
     *
     * Examples:
     *
     * GET:
     *
     * /api/escrow/payment/status?reference=SDM-000033
     *
     * POST:
     *
     * {
     *     "reference": "SDM-000033"
     * }
     *
     * ---------------------------------------------------------
     *
     * This endpoint reads the escrow database.
     *
     * It does NOT call Paystack.
     *
     * The webhook is responsible for changing:
     *
     * pending -> paid
     *
     * ---------------------------------------------------------
     */
    public function paymentStatus(): void
    {
        try {

            Logger::write(
                'escrow_api_controller',
                [
                    'step' =>
                        'PAYMENT_STATUS_REQUEST',

                    'method' =>
                        $_SERVER['REQUEST_METHOD']
                        ?? null,

                    'uri' =>
                        $_SERVER['REQUEST_URI']
                        ?? null,
                ]
            );


            $input =
                $this->input();


            /*
            |--------------------------------------------------------------------------
            | GET Query Parameters
            |--------------------------------------------------------------------------
            */

            if (
                empty($input['reference'])
                &&
                empty($input['escrow_reference'])
            ) {

                if (
                    isset(
                        $_GET['reference']
                    )
                ) {

                    $input['reference'] =
                        $_GET['reference'];

                }
                elseif (
                    isset(
                        $_GET['escrow_reference']
                    )
                ) {

                    $input['escrow_reference'] =
                        $_GET['escrow_reference'];
                }
            }


            $reference =
                $this->normalizeReference(
                    $input
                );


            Logger::write(
                'escrow_api_controller',
                [
                    'step' =>
                        'PAYMENT_STATUS_INPUT',

                    'reference' =>
                        $reference,
                ]
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
                            'Escrow reference is required.',
                    ],
                    422
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Get Escrow
            |--------------------------------------------------------------------------
            |
            | The service owns escrow lookup.
            |
            | No direct model fallback is used here.
            |--------------------------------------------------------------------------
            */

            $escrow =
                $this->service
                    ->getEscrowByReference(
                        $reference
                    );


            if (
                !is_array($escrow)
                ||
                $escrow === []
            ) {

                Logger::write(
                    'escrow_api_controller',
                    [
                        'step' =>
                            'PAYMENT_STATUS_ESCROW_NOT_FOUND',

                        'reference' =>
                            $reference,
                    ]
                );


                $this->json(
                    [
                        'success' => false,

                        'message' =>
                            'Escrow transaction not found.',

                        'reference' =>
                            $reference,
                    ],
                    404
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Normalize Escrow Status
            |--------------------------------------------------------------------------
            */

            $status =
                strtolower(
                    trim(
                        (string)(
                            $escrow['status']
                            ?? 'pending'
                        )
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | Payment Reference
            |--------------------------------------------------------------------------
            */

            $paymentReference =
                trim(
                    (string)(
                        $escrow['payment_reference']
                        ?? ''
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | Payment Method
            |--------------------------------------------------------------------------
            */

            $paymentMethod =
                strtoupper(
                    trim(
                        (string)(
                            $escrow['payment_method']
                            ?? 'paystack'
                        )
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | Currency
            |--------------------------------------------------------------------------
            */

            $currency =
                strtoupper(
                    trim(
                        (string)(
                            $escrow['currency']
                            ?? 'NGN'
                        )
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | Amount
            |--------------------------------------------------------------------------
            |
            | total_amount is the authoritative buyer-facing amount
            | when it exists.
            |--------------------------------------------------------------------------
            */

            $amount =
                $this->extractPublicAmount(
                    $escrow
                );


            /*
            |--------------------------------------------------------------------------
            | Paid Status
            |--------------------------------------------------------------------------
            */

            $paidStatuses = [
                'paid',
                'item_sent',
                'awaiting_payout',
                'buyer_confirmed',
                'completed',
            ];


            $paymentCompleted =
                in_array(
                    $status,
                    $paidStatuses,
                    true
                )
                &&
                $paymentReference !== '';


            /*
            |--------------------------------------------------------------------------
            | Public Response
            |--------------------------------------------------------------------------
            |
            | Do not expose internal IDs or sensitive account information.
            |--------------------------------------------------------------------------
            */

            $response = [

                'success' =>
                    true,

                'reference' =>
                    $reference,

                'status' =>
                    $status,

                'payment_completed' =>
                    $paymentCompleted,

                'payment_reference' =>
                    $paymentReference !== ''
                        ? $paymentReference
                        : null,

                'payment_method' =>
                    $paymentMethod,

                'amount' =>
                    $amount,

                'currency' =>
                    $currency,
            ];


            Logger::write(
                'escrow_api_controller',
                [
                    'step' =>
                        'PAYMENT_STATUS_COMPLETE',

                    'reference' =>
                        $reference,

                    'status' =>
                        $status,

                    'payment_completed' =>
                        $paymentCompleted,

                    'has_payment_reference' =>
                        $paymentReference !== '',
                ]
            );


            $this->json(
                $response,
                200
            );

        }
        catch (Throwable $e) {

            $this->handleException(
                'PAYMENT_STATUS_EXCEPTION',
                $e,
                'Unable to retrieve escrow payment status.'
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * NORMALIZE REFERENCE
     * ---------------------------------------------------------
     */
    protected function normalizeReference(
        array $input
    ): string {

        return strtoupper(
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
    }


    /**
     * ---------------------------------------------------------
     * NORMALIZE EMAIL
     * ---------------------------------------------------------
     */
    protected function normalizeEmail(
        array $input
    ): string {

        return trim(
            (string)(
                $input['email']
                ??
                $input['buyer_email']
                ??
                ''
            )
        );
    }


    /**
     * ---------------------------------------------------------
     * EXTRACT PUBLIC AMOUNT
     * ---------------------------------------------------------
     */
    protected function extractPublicAmount(
        array $escrow
    ): ?float {

        if (
            array_key_exists(
                'total_amount',
                $escrow
            )
            &&
            is_numeric(
                $escrow['total_amount']
            )
        ) {

            return (float)$escrow['total_amount'];
        }


        if (
            array_key_exists(
                'amount',
                $escrow
            )
            &&
            is_numeric(
                $escrow['amount']
            )
        ) {

            return (float)$escrow['amount'];
        }


        return null;
    }


    /**
     * ---------------------------------------------------------
     * DETERMINE HTTP STATUS FROM SERVICE RESULT
     * ---------------------------------------------------------
     */
    protected function statusForResult(
        array $result,
        int $defaultStatus = 400
    ): int {

        if (
            ($result['success'] ?? false)
            ===
            true
        ) {

            return 200;
        }


        $message =
            strtolower(
                trim(
                    (string)(
                        $result['message']
                        ?? ''
                    )
                )
            );


        if (
            str_contains(
                $message,
                'required'
            )
            ||
            str_contains(
                $message,
                'invalid'
            )
        ) {

            return 422;
        }


        if (
            str_contains(
                $message,
                'not found'
            )
        ) {

            return 404;
        }


        if (
            str_contains(
                $message,
                'not authorized'
            )
            ||
            str_contains(
                $message,
                'unauthorized'
            )
            ||
            str_contains(
                $message,
                'not allowed'
            )
        ) {

            return 403;
        }


        if (
            str_contains(
                $message,
                'already'
            )
        ) {

            return 409;
        }


        return $defaultStatus;
    }


    /**
     * ---------------------------------------------------------
     * READ REQUEST INPUT
     * ---------------------------------------------------------
     *
     * Supports:
     *
     * JSON
     * application/x-www-form-urlencoded
     * multipart/form-data
     *
     * ---------------------------------------------------------
     */
    protected function input(): array
    {
        try {

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


            if (
                !empty($_POST)
            ) {

                return $_POST;
            }


            return [];

        }
        catch (Throwable $e) {

            Logger::write(
                'escrow_api_controller_error',
                [
                    'step' =>
                        'INPUT_READ_EXCEPTION',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );


            return [];
        }
    }


    /**
     * ---------------------------------------------------------
     * HANDLE EXCEPTION
     * ---------------------------------------------------------
     */
    protected function handleException(
        string $step,
        Throwable $e,
        string $message
    ): void {

        Logger::write(
            'escrow_api_controller_error',
            [
                'step' =>
                    $step,

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
                'success' => false,

                'message' =>
                    $message,
            ],
            500
        );
    }


    /**
     * ---------------------------------------------------------
     * JSON RESPONSE
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
}