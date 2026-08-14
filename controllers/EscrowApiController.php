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

    /**
     * ---------------------------------------------------------
     * Constructor
     * ---------------------------------------------------------
     */
    public function __construct()
    {
        $this->service = new EscrowApiService();

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
                        $_SERVER['REQUEST_METHOD'] ?? null,
                    'uri' =>
                        $_SERVER['REQUEST_URI'] ?? null,
                ]
            );

            $input = $this->input();

            Logger::write(
                'escrow_api_controller',
                [
                    'step' => 'VERIFY_INPUT',
                    'input' => $input,
                ]
            );

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
                        $e->getLine(),
                    'trace' =>
                        $e->getTraceAsString(),
                ]
            );

            $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Unable to verify escrow.',
                ],
                500
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
     * Expected:
     *
     * {
     *     "reference": "SDM-000033",
     *     "phone": "08012345678"
     * }
     *
     * IMPORTANT:
     *
     * This does not directly pay the seller.
     *
     * It confirms that the buyer has received the item.
     *
     * The existing EscrowConfirmationService handles
     * the actual confirmation workflow.
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
                        $_SERVER['REQUEST_METHOD'] ?? null,
                    'uri' =>
                        $_SERVER['REQUEST_URI'] ?? null,
                ]
            );

            $input = $this->input();

            Logger::write(
                'escrow_api_controller',
                [
                    'step' => 'RELEASE_INPUT',
                    'input' => $input,
                ]
            );

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

            if (
                ($result['success'] ?? false)
            ) {

                $status = 200;

            }
            else {

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
                        'not authorized'
                    )
                    ||
                    str_contains(
                        $message,
                        'not registered'
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
                        $e->getTraceAsString(),
                ]
            );

            $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Unable to release escrow.',
                ],
                500
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * PAYMENT INITIALIZATION
     * ---------------------------------------------------------
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
     * The client does NOT supply the amount.
     *
     * PaystackEscrowPaymentService loads the authoritative
     * amount from the escrow record.
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
                        $_SERVER['REQUEST_METHOD'] ?? null,
                    'uri' =>
                        $_SERVER['REQUEST_URI'] ?? null,
                ]
            );

            $input = $this->input();

            Logger::write(
                'escrow_api_controller',
                [
                    'step' => 'PAYMENT_INPUT',
                    'input' => $input,
                ]
            );

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

            if ($reference === '') {

                Logger::write(
                    'escrow_api_controller_error',
                    [
                        'step' =>
                            'PAYMENT_REFERENCE_MISSING',
                    ]
                );

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

            $callbackUrl =
                trim(
                    (string)(
                        $input['callback_url']
                        ?? ''
                    )
                );

            if ($callbackUrl === '') {
                $callbackUrl = null;
            }

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
                        $callbackUrl,
                ]
            );

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
                        $result,
                ]
            );

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

            if (
                ($result['success'] ?? false)
            ) {

                $this->json(
                    $result,
                    200
                );

                return;
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
                        $e->getTraceAsString(),
                ]
            );

            $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Unable to initialize escrow payment.',
                ],
                500
            );
        }
    }


    /**
     * Paystack browser callback.
     *
     * A callback is informational only; it never marks an escrow paid.
     * The webhook remains the authoritative payment confirmation path.
     */
    public function callback(): void
    {
        Logger::write('escrow_api_controller', [
            'step' => 'PAYMENT_CALLBACK',
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
        ]);

        $this->paymentStatus();
    }


    /**
     * ---------------------------------------------------------
     * PAYMENT STATUS
     * ---------------------------------------------------------
     *
     * GET /api/escrow/payment/status?reference=SDM-000033
     *
     * OR
     *
     * POST /api/escrow/payment/status
     *
     * Expected:
     *
     * {
     *     "reference": "SDM-000033"
     * }
     *
     * ---------------------------------------------------------
     *
     * Returns the current payment state of the escrow.
     *
     * This endpoint does NOT call Paystack.
     *
     * The escrow database is the source of truth here because
     * the Paystack webhook is responsible for recording the
     * successful payment against the escrow.
     *
     * ---------------------------------------------------------
     */
    public function paymentStatus(): void
    {
        try {

            Logger::write(
                'escrow_api_controller',
                [
                    'step' => 'PAYMENT_STATUS_REQUEST',
                    'method' =>
                        $_SERVER['REQUEST_METHOD'] ?? null,
                    'uri' =>
                        $_SERVER['REQUEST_URI'] ?? null,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Read Input
            |--------------------------------------------------------------------------
            */

            $input = $this->input();

            /*
            |--------------------------------------------------------------------------
            | GET fallback
            |--------------------------------------------------------------------------
            |
            | Because payment/status supports GET as well as POST,
            | read the reference from $_GET when it wasn't found
            | in JSON/POST input.
            |
            */

            if (
                empty($input['reference'])
                &&
                empty($input['escrow_reference'])
            ) {

                if (
                    isset($_GET['reference'])
                ) {

                    $input['reference'] =
                        $_GET['reference'];

                }
                elseif (
                    isset($_GET['escrow_reference'])
                ) {

                    $input['escrow_reference'] =
                        $_GET['escrow_reference'];
                }
            }

            Logger::write(
                'escrow_api_controller',
                [
                    'step' =>
                        'PAYMENT_STATUS_INPUT',
                    'input' =>
                        $input,
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
            | Validate Reference
            |--------------------------------------------------------------------------
            */

            if ($reference === '') {

                Logger::write(
                    'escrow_api_controller_error',
                    [
                        'step' =>
                            'PAYMENT_STATUS_REFERENCE_MISSING',
                    ]
                );

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
            | Lookup Escrow
            |--------------------------------------------------------------------------
            */

            $escrow =
                $this->service
                    ->getEscrowByReference(
                        $reference
                    );

            /*
            |--------------------------------------------------------------------------
            | Fallback
            |--------------------------------------------------------------------------
            |
            | If the service does not expose a lookup method,
            | we use a fresh Escrow model directly.
            |
            | This keeps the API endpoint independent from
            | payment-processing logic.
            |
            */

            if ($escrow === null) {

                Logger::write(
                    'escrow_api_controller',
                    [
                        'step' =>
                            'PAYMENT_STATUS_SERVICE_LOOKUP_EMPTY',
                        'reference' =>
                            $reference,
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | Direct model lookup
                |--------------------------------------------------------------------------
                */

                $escrowModel =
                    new \Models\Escrow();

                $escrow =
                    $escrowModel
                        ->findByReference(
                            $reference
                        );
            }

            /*
            |--------------------------------------------------------------------------
            | Escrow Not Found
            |--------------------------------------------------------------------------
            */

            if (!$escrow) {

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
            | Extract Payment Information
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

            $paymentReference =
                trim(
                    (string)(
                        $escrow['payment_reference']
                        ?? ''
                    )
                );

            $paymentMethod =
                trim(
                    (string)(
                        $escrow['payment_method']
                        ?? 'paystack'
                    )
                );

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
            | For payment status we expose the authoritative escrow
            | amount. Prefer total_amount because that is the amount
            | charged to the buyer when the payment service includes
            | fees.
            |
            */

            $amount = null;

            if (
                array_key_exists(
                    'total_amount',
                    $escrow
                )
            ) {

                $amount =
                    (float)$escrow['total_amount'];

            }
            elseif (
                array_key_exists(
                    'amount',
                    $escrow
                )
            ) {

                $amount =
                    (float)$escrow['amount'];
            }

            /*
            |--------------------------------------------------------------------------
            | Determine Paid State
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
            | Safe Public Response
            |--------------------------------------------------------------------------
            |
            | NEVER expose:
            |
            | - buyer_id
            | - seller_id
            | - database ID
            | - phone numbers
            | - email addresses
            | - release codes
            | - payout details
            |
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

            Logger::write(
                'escrow_api_controller_error',
                [
                    'step' =>
                        'PAYMENT_STATUS_EXCEPTION',
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
                        'Unable to retrieve escrow payment status.',
                ],
                500
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * READ INPUT
     * ---------------------------------------------------------
     *
     * Supports:
     *
     * - JSON
     * - application/x-www-form-urlencoded
     * - multipart/form-data
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