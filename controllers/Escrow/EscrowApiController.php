<?php

declare(strict_types=1);

namespace Controllers;

use Core\Logger;
use Services\Escrow\EscrowApiService;
use Services\Escrow\PaystackEscrowPaymentService;
use Throwable;

class EscrowApiController
{
    protected EscrowApiService $escrowService;

    protected PaystackEscrowPaymentService $paymentService;


    /**
     * ---------------------------------------------------------
     * Constructor
     * ---------------------------------------------------------
     */
    public function __construct()
    {
        $this->escrowService =
            new EscrowApiService();

        $this->paymentService =
            new PaystackEscrowPaymentService();

        Logger::write(
            'escrow_api_controller',
            [
                'step' => 'CONSTRUCTOR'
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * VERIFY ESCROW
     * ---------------------------------------------------------
     *
     * POST:
     *
     * /api/escrow/verify
     *
     * Request:
     *
     * {
     *     "reference": "SDM-000033"
     * }
     *
     * Used by:
     *
     * - Website
     * - Mobile App
     * - SMS
     * - USSD
     *
     * ---------------------------------------------------------
     */
    public function verify(): void
    {
        try {

            Logger::write(
                'escrow_api_controller',
                [
                    'step' => 'VERIFY_REQUEST'
                ]
            );


            $input =
                $this->input();


            $reference =
                strtoupper(
                    trim(
                        (string)(
                            $input['reference']
                            ??
                            ''
                        )
                    )
                );


            Logger::write(
                'escrow_api_controller',
                [
                    'step' => 'VERIFY_INPUT',
                    'reference' => $reference
                ]
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
            |---------------------------------------------------------
            | Delegate to Escrow API Service
            |---------------------------------------------------------
            */

            $result =
                $this->escrowService
                    ->verify(
                        $reference
                    );


            Logger::write(
                'escrow_api_controller',
                [
                    'step' => 'VERIFY_SERVICE_RESULT',
                    'reference' => $reference,
                    'result' => $result
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
                    'step' => 'VERIFY_EXCEPTION',
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
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
     * PAY ESCROW
     * ---------------------------------------------------------
     *
     * POST:
     *
     * /api/escrow/pay
     *
     * Request:
     *
     * {
     *     "reference": "SDM-000033",
     *     "email": "buyer@example.com"
     * }
     *
     * IMPORTANT:
     *
     * The amount is NOT accepted from the client.
     *
     * The amount comes from the existing escrow record.
     *
     * This prevents a client from attempting:
     *
     * {
     *     "reference": "SDM-000033",
     *     "amount": 1
     * }
     *
     * ---------------------------------------------------------
     */
    public function pay(): void
    {
        try {

            Logger::write(
                'escrow_api_controller',
                [
                    'step' => 'PAY_REQUEST'
                ]
            );


            $input =
                $this->input();


            $reference =
                strtoupper(
                    trim(
                        (string)(
                            $input['reference']
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
                        ''
                    )
                );


            Logger::write(
                'escrow_api_controller',
                [
                    'step' => 'PAY_INPUT',
                    'reference' => $reference,
                    'email' => $email
                ]
            );


            /*
            |---------------------------------------------------------
            | Validate Reference
            |---------------------------------------------------------
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
            |---------------------------------------------------------
            | Validate Email
            |---------------------------------------------------------
            |
            | Paystack transaction initialization requires
            | an email address.
            |
            */

            if (
                $email === ''
                ||
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {

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
            |---------------------------------------------------------
            | Initialize Escrow Payment
            |---------------------------------------------------------
            */

            $result =
                $this->paymentService
                    ->initialize(
                        $reference,
                        $email
                    );


            Logger::write(
                'escrow_api_controller',
                [
                    'step' => 'PAYMENT_SERVICE_RESULT',
                    'reference' => $reference,
                    'result' => $result
                ]
            );


            $status =
                ($result['success'] ?? false)
                    ? 200
                    : 400;


            $this->json(
                $result,
                $status
            );


        }
        catch (Throwable $e) {

            Logger::write(
                'escrow_api_controller_error',
                [
                    'step' => 'PAY_EXCEPTION',
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
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


    /**
     * ---------------------------------------------------------
     * RELEASE / CONFIRM RECEIPT
     * ---------------------------------------------------------
     *
     * POST:
     *
     * /api/escrow/release
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
     * "release" here means:
     *
     * BUYER CONFIRMS RECEIPT.
     *
     * It does NOT blindly transfer money.
     *
     * The existing EscrowConfirmationService and escrow
     * workflow remain responsible for the actual payout process.
     *
     * ---------------------------------------------------------
     */
    public function release(): void
    {
        try {

            Logger::write(
                'escrow_api_controller',
                [
                    'step' => 'RELEASE_REQUEST'
                ]
            );


            $input =
                $this->input();


            $reference =
                strtoupper(
                    trim(
                        (string)(
                            $input['reference']
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
                        $input['phone_number']
                        ??
                        ''
                    )
                );


            Logger::write(
                'escrow_api_controller',
                [
                    'step' => 'RELEASE_INPUT',
                    'reference' => $reference,
                    'phone' => $phone
                ]
            );


            /*
            |---------------------------------------------------------
            | Validate Reference
            |---------------------------------------------------------
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
            |---------------------------------------------------------
            | Validate Phone
            |---------------------------------------------------------
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
            |---------------------------------------------------------
            | Delegate Buyer Confirmation
            |---------------------------------------------------------
            */

            $result =
                $this->escrowService
                    ->confirmReceipt(
                        $reference,
                        $phone
                    );


            Logger::write(
                'escrow_api_controller',
                [
                    'step' => 'RELEASE_SERVICE_RESULT',
                    'reference' => $reference,
                    'result' => $result
                ]
            );


            $status =
                ($result['success'] ?? false)
                    ? 200
                    : 400;


            $this->json(
                $result,
                $status
            );


        }
        catch (Throwable $e) {

            Logger::write(
                'escrow_api_controller_error',
                [
                    'step' => 'RELEASE_EXCEPTION',
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );


            $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Unable to confirm receipt.'
                ],
                500
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * Read JSON / POST Input
     * ---------------------------------------------------------
     *
     * Supports:
     *
     * application/json
     * application/x-www-form-urlencoded
     * multipart/form-data
     *
     * ---------------------------------------------------------
     */
    protected function input(): array
    {
        try {

            $contentType =
                $_SERVER['CONTENT_TYPE']
                ??
                '';


            /*
            |---------------------------------------------------------
            | JSON
            |---------------------------------------------------------
            */

            if (
                str_contains(
                    strtolower($contentType),
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


                if (
                    is_array($decoded)
                ) {

                    return $decoded;
                }


                return [];
            }


            /*
            |---------------------------------------------------------
            | Standard POST
            |---------------------------------------------------------
            */

            return $_POST;


        }
        catch (Throwable $e) {

            Logger::write(
                'escrow_api_controller_error',
                [
                    'step' => 'INPUT_PARSE_FAILED',
                    'message' => $e->getMessage()
                ]
            );


            return [];
        }
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
}