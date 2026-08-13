<?php

declare(strict_types=1);

namespace Services\Escrow;

use Core\Logger;
use Core\ReplyFactory;
use Models\BotNotification;
use Models\User;
use Modules\Escrow\Models\Escrow;
use Services\Payments\PaystackGateway;
use Throwable;

class PaystackEscrowPaymentService
{
    protected Escrow $escrowModel;

    protected PaystackGateway $gateway;

    public function __construct()
    {
        $this->escrowModel =
            new Escrow();

        $this->gateway =
            new PaystackGateway();

        Logger::write(
            'paystack_escrow_payment',
            [
                'step' => 'CONSTRUCTOR'
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * INITIALIZE ESCROW PAYMENT
     * ---------------------------------------------------------
     *
     * Called by:
     *
     * EscrowApiController::payment()
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
     * The client does NOT provide the amount.
     *
     * The amount is loaded from the existing escrow record.
     *
     * ---------------------------------------------------------
     */
    public function initialize(
        string $reference,
        string $email,
        ?string $callbackUrl = null
    ): array {

        try {

            $reference =
                strtoupper(
                    trim($reference)
                );

            $email =
                trim($email);


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'INITIALIZE_START',

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
            | Validate Reference
            |--------------------------------------------------------------------------
            */

            if ($reference === '') {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_REFERENCE_MISSING'
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Escrow reference is required.'
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
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_EMAIL_INVALID',

                        'reference' =>
                            $reference,

                        'email' =>
                            $email
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'A valid email address is required.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Locate Escrow
            |--------------------------------------------------------------------------
            |
            | We first try the existing model's reference lookup if available.
            |
            | This keeps the service compatible with different versions of
            | the Escrow model.
            |--------------------------------------------------------------------------
            */

            $escrow =
                $this->findEscrowByReference(
                    $reference
                );


            if (!$escrow) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_ESCROW_NOT_FOUND',

                        'reference' =>
                            $reference
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Escrow transaction not found.'
                ];
            }


            $escrowId =
                (int)(
                    $escrow['id']
                    ?? 0
                );


            if ($escrowId <= 0) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_ESCROW_ID_INVALID',

                        'reference' =>
                            $reference,

                        'escrow' =>
                            $escrow
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Invalid escrow transaction.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Escrow Status
            |--------------------------------------------------------------------------
            */

            $escrowStatus =
                strtolower(
                    trim(
                        (string)(
                            $escrow['status']
                            ?? ''
                        )
                    )
                );


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'INITIALIZE_ESCROW_LOADED',

                    'escrow_id' =>
                        $escrowId,

                    'reference' =>
                        $reference,

                    'status' =>
                        $escrowStatus
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Prevent Payment After Escrow Is Already Paid
            |--------------------------------------------------------------------------
            */

            if (
                in_array(
                    $escrowStatus,
                    [
                        'paid',
                        'item_sent',
                        'buyer_confirmed',
                        'completed'
                    ],
                    true
                )
            ) {

                Logger::write(
                    'paystack_escrow_payment',
                    [
                        'step' =>
                            'INITIALIZE_ALREADY_PAID',

                        'escrow_id' =>
                            $escrowId,

                        'reference' =>
                            $reference,

                        'status' =>
                            $escrowStatus
                    ]
                );

                return [
                    'success' => false,
                    'already_processed' => true,
                    'message' =>
                        'This escrow payment has already been processed.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Determine Authoritative Amount
            |--------------------------------------------------------------------------
            */

            $amount =
                $this->extractEscrowAmount(
                    $escrow
                );


            if ($amount <= 0) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_AMOUNT_INVALID',

                        'escrow_id' =>
                            $escrowId,

                        'reference' =>
                            $reference,

                        'escrow' =>
                            $escrow
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Escrow payment amount is invalid.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Paystack Callback
            |--------------------------------------------------------------------------
            */

            if (
                $callbackUrl === null
                ||
                trim($callbackUrl) === ''
            ) {

                $callbackUrl =
                    $this->defaultCallbackUrl();
            }


            /*
            |--------------------------------------------------------------------------
            | Paystack Metadata
            |--------------------------------------------------------------------------
            */

            $metadata = [

                'type' =>
                    'escrow',

                'escrow_id' =>
                    $escrowId,

                'escrow_reference' =>
                    $reference,

                'payment_type' =>
                    'escrow',

                'source' =>
                    'sendam_escrow'

            ];


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'PAYSTACK_INITIALIZE_START',

                    'escrow_id' =>
                        $escrowId,

                    'reference' =>
                        $reference,

                    'amount' =>
                        $amount,

                    'email' =>
                        $email,

                    'callback_url' =>
                        $callbackUrl,

                    'metadata' =>
                        $metadata
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | IMPORTANT PAYMENT REFERENCE
            |--------------------------------------------------------------------------
            |
            | The escrow reference itself is used as the Paystack reference.
            |
            | This makes verification and webhook processing deterministic.
            |--------------------------------------------------------------------------
            */

            $paystackReference =
                $reference;


            /*
            |--------------------------------------------------------------------------
            | Initialize Paystack
            |--------------------------------------------------------------------------
            */

            $result =
                $this->gateway->initialize(
                    $amount,
                    $email,
                    $paystackReference,
                    $callbackUrl,
                    $metadata
                );


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'PAYSTACK_INITIALIZE_RESULT',

                    'escrow_id' =>
                        $escrowId,

                    'reference' =>
                        $reference,

                    'result' =>
                        $result
                ]
            );


            if (
                !is_array($result)
                ||
                !($result['success'] ?? false)
            ) {

                return [
                    'success' => false,
                    'message' =>
                        $result['message']
                        ??
                        'Unable to initialize escrow payment.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Final Response
            |--------------------------------------------------------------------------
            */

            return [

                'success' =>
                    true,

                'message' =>
                    'Escrow payment initialized successfully.',

                'escrow_id' =>
                    $escrowId,

                'reference' =>
                    $reference,

                'paystack_reference' =>
                    $result['reference']
                    ??
                    $paystackReference,

                'amount' =>
                    $amount,

                'currency' =>
                    'NGN',

                'authorization_url' =>
                    $result['authorization_url']
                    ??
                    null,

                'access_code' =>
                    $result['access_code']
                    ??
                    null

            ];

        }
        catch (Throwable $e) {

            Logger::write(
                'paystack_escrow_payment_error',
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
                'success' => false,
                'message' =>
                    'Unable to initialize escrow payment.'
            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * PROCESS VERIFIED PAYMENT
     * ---------------------------------------------------------
     *
     * Called by:
     *
     * EscrowPaystackWebhookListener
     *
     * IMPORTANT:
     *
     * The listener has ALREADY verified the Paystack transaction.
     *
     * Therefore this method MUST NOT call PaystackVerifier again.
     *
     * ---------------------------------------------------------
     */
    public function process(
    array $transaction
): array {

    $paymentReference = strtoupper(
        trim(
            (string)(
                $transaction['reference']
                ?? ''
            )
        )
    );

    try {

        Logger::write(
            'paystack_escrow_payment',
            [
                'step' => 'PROCESS_START',
                'payment_reference' => $paymentReference
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | VALIDATE TRANSACTION
        |--------------------------------------------------------------------------
        */

        if ($transaction === []) {

            return [
                'success' => false,
                'retry' => false,
                'message' => 'Verified payment data is unavailable.'
            ];
        }


        if ($paymentReference === '') {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' => 'PROCESS_PAYMENT_REFERENCE_MISSING'
                ]
            );

            return [
                'success' => false,
                'retry' => false,
                'message' => 'Payment reference is missing.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | PAYSTACK STATUS
        |--------------------------------------------------------------------------
        */

        $paymentStatus = strtolower(
            trim(
                (string)(
                    $transaction['status']
                    ?? ''
                )
            )
        );


        if ($paymentStatus !== 'success') {

            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' => 'PROCESS_PAYMENT_NOT_SUCCESSFUL',
                    'payment_reference' => $paymentReference,
                    'status' => $paymentStatus
                ]
            );

            return [
                'success' => false,
                'retry' => false,
                'message' => 'Payment is not successful.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | METADATA
        |--------------------------------------------------------------------------
        */

        $metadata =
            $transaction['metadata']
            ?? [];


        if (!is_array($metadata)) {
            $metadata = [];
        }


        Logger::write(
            'paystack_escrow_payment',
            [
                'step' => 'PROCESS_METADATA',
                'payment_reference' => $paymentReference,
                'metadata' => $metadata
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | PAYMENT TYPE
        |--------------------------------------------------------------------------
        */

        $paymentType = strtolower(
            trim(
                (string)(
                    $metadata['type']
                    ?? ''
                )
            )
        );


        if (
            !in_array(
                $paymentType,
                [
                    'escrow',
                    'escrow_payment'
                ],
                true
            )
        ) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' => 'PROCESS_INVALID_PAYMENT_TYPE',
                    'payment_reference' => $paymentReference,
                    'type' => $paymentType
                ]
            );

            return [
                'success' => false,
                'retry' => false,
                'message' =>
                    'This Paystack transaction is not an escrow payment.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | ESCROW ID
        |--------------------------------------------------------------------------
        */

        $escrowId = (int)(
            $metadata['escrow_id']
            ?? 0
        );


        if ($escrowId <= 0) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' => 'PROCESS_ESCROW_ID_MISSING',
                    'payment_reference' => $paymentReference,
                    'metadata' => $metadata
                ]
            );

            return [
                'success' => false,
                'retry' => false,
                'message' =>
                    'Escrow ID is missing from payment metadata.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | LOAD ESCROW
        |--------------------------------------------------------------------------
        */

        Logger::write(
            'paystack_escrow_payment',
            [
                'step' => 'PROCESS_ESCROW_LOOKUP',
                'escrow_id' => $escrowId,
                'payment_reference' => $paymentReference
            ]
        );


        $escrow =
            $this->escrowModel->find(
                $escrowId
            );


        if (
            !is_array($escrow)
            ||
            empty($escrow)
        ) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' => 'PROCESS_ESCROW_NOT_FOUND',
                    'escrow_id' => $escrowId,
                    'payment_reference' => $paymentReference
                ]
            );

            return [
                'success' => false,
                'retry' => false,
                'message' =>
                    'Escrow transaction not found.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | ESCROW REFERENCE
        |--------------------------------------------------------------------------
        */

        $escrowReference = strtoupper(
            trim(
                (string)(
                    $escrow['reference']
                    ?? ''
                )
            )
        );


        if ($escrowReference === '') {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' => 'PROCESS_ESCROW_REFERENCE_MISSING',
                    'escrow_id' => $escrowId
                ]
            );

            return [
                'success' => false,
                'retry' => false,
                'message' =>
                    'Escrow reference is missing.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | METADATA ESCROW REFERENCE
        |--------------------------------------------------------------------------
        */

        $metadataEscrowReference = strtoupper(
            trim(
                (string)(
                    $metadata['escrow_reference']
                    ??
                    $metadata['reference']
                    ??
                    ''
                )
            )
        );


        if (
            $metadataEscrowReference !== ''
            &&
            $metadataEscrowReference !== $escrowReference
        ) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' => 'ESCROW_REFERENCE_MISMATCH',
                    'payment_reference' => $paymentReference,
                    'metadata_reference' =>
                        $metadataEscrowReference,
                    'database_reference' =>
                        $escrowReference,
                    'escrow_id' => $escrowId
                ]
            );

            return [
                'success' => false,
                'retry' => false,
                'message' =>
                    'Escrow reference mismatch.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | VERIFY PAYMENT REFERENCE
        |--------------------------------------------------------------------------
        |
        | The payment reference must either:
        |
        | 1. Already be stored against this escrow, OR
        | 2. Be the reference generated for this escrow payment.
        |
        | We DO NOT require:
        |
        | Paystack reference === escrow reference
        |
        */

        $existingPaymentReference = strtoupper(
            trim(
                (string)(
                    $escrow['payment_reference']
                    ?? ''
                )
            )
        );


        if (
            $existingPaymentReference !== ''
            &&
            $existingPaymentReference !== $paymentReference
        ) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' => 'PAYMENT_REFERENCE_CONFLICT',
                    'escrow_id' => $escrowId,
                    'escrow_reference' => $escrowReference,
                    'existing_payment_reference' =>
                        $existingPaymentReference,
                    'incoming_payment_reference' =>
                        $paymentReference
                ]
            );

            return [
                'success' => false,
                'retry' => false,
                'message' =>
                    'This escrow is already linked to another payment.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | AMOUNT
        |--------------------------------------------------------------------------
        */

        $escrowAmount =
            $this->extractEscrowAmount(
                $escrow
            );


        if ($escrowAmount <= 0) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' => 'INVALID_ESCROW_AMOUNT',
                    'escrow_id' => $escrowId,
                    'amount' => $escrowAmount
                ]
            );

            return [
                'success' => false,
                'retry' => false,
                'message' =>
                    'Escrow amount is invalid.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | CALCULATE EXPECTED BUYER PAYMENT
        |--------------------------------------------------------------------------
        |
        | This is important.
        |
        | Paystack receives the buyer total, not necessarily the
        | raw escrow amount.
        |
        */

        $fees =
            $this->calculateBuyerTotal(
                $escrowAmount
            );


        $expectedBuyerTotal =
            (float)(
                $fees['buyer_total']
                ?? $escrowAmount
            );


        /*
        |--------------------------------------------------------------------------
        | PAYSTACK AMOUNT
        |--------------------------------------------------------------------------
        */

        $paystackAmountKobo = (int)(
            $transaction['amount']
            ?? 0
        );


        if ($paystackAmountKobo <= 0) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' => 'PAYSTACK_AMOUNT_INVALID',
                    'escrow_id' => $escrowId,
                    'payment_reference' =>
                        $paymentReference,
                    'received_amount' =>
                        $paystackAmountKobo
                ]
            );

            return [
                'success' => false,
                'retry' => false,
                'message' =>
                    'Paystack payment amount is invalid.'
            ];
        }


        $expectedAmountKobo =
            (int)round(
                $expectedBuyerTotal * 100
            );


        Logger::write(
            'paystack_escrow_payment',
            [
                'step' => 'AMOUNT_VALIDATION',
                'escrow_id' => $escrowId,
                'escrow_reference' => $escrowReference,
                'payment_reference' => $paymentReference,
                'escrow_amount' => $escrowAmount,
                'escrow_fee' =>
                    $fees['escrow_fee']
                    ?? 0,
                'paystack_fee' =>
                    $fees['paystack_fee']
                    ?? 0,
                'expected_buyer_total' =>
                    $expectedBuyerTotal,
                'expected_kobo' =>
                    $expectedAmountKobo,
                'received_kobo' =>
                    $paystackAmountKobo
            ]
        );


        if (
            $expectedAmountKobo
            !==
            $paystackAmountKobo
        ) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' => 'AMOUNT_MISMATCH',
                    'escrow_id' => $escrowId,
                    'payment_reference' =>
                        $paymentReference,
                    'expected_kobo' =>
                        $expectedAmountKobo,
                    'received_kobo' =>
                        $paystackAmountKobo
                ]
            );

            return [
                'success' => false,
                'retry' => false,
                'message' =>
                    'Payment amount does not match escrow payment total.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | CURRENCY
        |--------------------------------------------------------------------------
        */

        $currency = strtoupper(
            trim(
                (string)(
                    $transaction['currency']
                    ??
                    $escrow['currency']
                    ??
                    'NGN'
                )
            )
        );


        $escrowCurrency = strtoupper(
            trim(
                (string)(
                    $escrow['currency']
                    ??
                    'NGN'
                )
            )
        );


        if (
            $currency !== ''
            &&
            $escrowCurrency !== ''
            &&
            $currency !== $escrowCurrency
        ) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' => 'CURRENCY_MISMATCH',
                    'escrow_id' => $escrowId,
                    'payment_reference' =>
                        $paymentReference,
                    'paystack_currency' =>
                        $currency,
                    'escrow_currency' =>
                        $escrowCurrency
                ]
            );

            return [
                'success' => false,
                'retry' => false,
                'message' =>
                    'Payment currency does not match escrow currency.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | DUPLICATE / ALREADY PROCESSED
        |--------------------------------------------------------------------------
        */

        $status = strtolower(
            trim(
                (string)(
                    $escrow['status']
                    ?? ''
                )
            )
        );


        if (
            in_array(
                $status,
                [
                    'paid',
                    'item_sent',
                    'awaiting_payout',
                    'buyer_confirmed',
                    'completed'
                ],
                true
            )
        ) {

            /*
             * If the escrow is already paid but the same payment
             * reference is missing, do not blindly overwrite it.
             */

            if (
                $existingPaymentReference !== ''
                &&
                $existingPaymentReference === $paymentReference
            ) {

                Logger::write(
                    'paystack_escrow_payment',
                    [
                        'step' => 'ALREADY_PROCESSED',
                        'escrow_id' => $escrowId,
                        'escrow_reference' =>
                            $escrowReference,
                        'payment_reference' =>
                            $paymentReference,
                        'status' => $status
                    ]
                );

                return [
                    'success' => true,
                    'retry' => false,
                    'already_processed' => true,
                    'message' =>
                        'Escrow payment has already been processed.',
                    'reference' =>
                        $escrowReference,
                    'payment_reference' =>
                        $paymentReference,
                    'escrow_id' =>
                        $escrowId,
                    'status' =>
                        $status
                ];
            }


            /*
             * Advanced status but no payment reference.
             *
             * Do not assume this webhook owns the escrow.
             */

            if (
                $existingPaymentReference === ''
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'ADVANCED_STATUS_WITHOUT_PAYMENT_REFERENCE',
                        'escrow_id' => $escrowId,
                        'status' => $status,
                        'incoming_reference' =>
                            $paymentReference
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Escrow is already advanced but has no matching payment reference.'
                ];
            }


            return [
                'success' => false,
                'retry' => false,
                'message' =>
                    'Escrow is already linked to another payment.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | ONLY PENDING ESCROW MAY BECOME PAID
        |--------------------------------------------------------------------------
        */

        if ($status !== 'pending') {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' => 'INVALID_ESCROW_STATUS',
                    'escrow_id' => $escrowId,
                    'status' => $status,
                    'payment_reference' =>
                        $paymentReference
                ]
            );

            return [
                'success' => false,
                'retry' => false,
                'message' =>
                    'Escrow cannot be marked as paid from its current status.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | MARK PAID
        |--------------------------------------------------------------------------
        */

        Logger::write(
            'paystack_escrow_payment',
            [
                'step' => 'MARK_PAID_START',
                'escrow_id' => $escrowId,
                'escrow_reference' =>
                    $escrowReference,
                'payment_reference' =>
                    $paymentReference
            ]
        );


        $paid =
            $this->escrowModel->markPaid(
                $escrowId,
                $paymentReference
            );


        Logger::write(
            'paystack_escrow_payment',
            [
                'step' => 'MARK_PAID_RESULT',
                'escrow_id' => $escrowId,
                'payment_reference' =>
                    $paymentReference,
                'result' => $paid
            ]
        );


        if (!$paid) {

            /*
             * Reload before declaring failure.
             *
             * Another webhook may have won the race.
             */

            $after =
                $this->escrowModel->find(
                    $escrowId
                );


            $afterStatus = strtolower(
                trim(
                    (string)(
                        $after['status']
                        ?? ''
                    )
                )
            );


            $afterPaymentReference = strtoupper(
                trim(
                    (string)(
                        $after['payment_reference']
                        ?? ''
                    )
                )
            );


            if (
                in_array(
                    $afterStatus,
                    [
                        'paid',
                        'item_sent',
                        'awaiting_payout',
                        'buyer_confirmed',
                        'completed'
                    ],
                    true
                )
                &&
                $afterPaymentReference === $paymentReference
            ) {

                Logger::write(
                    'paystack_escrow_payment',
                    [
                        'step' =>
                            'MARK_PAID_RACE_RECOVERED',
                        'escrow_id' =>
                            $escrowId,
                        'payment_reference' =>
                            $paymentReference,
                        'status' =>
                            $afterStatus
                    ]
                );

                return [
                    'success' => true,
                    'retry' => false,
                    'already_processed' => true,
                    'message' =>
                        'Escrow payment has already been processed.',
                    'reference' =>
                        $escrowReference,
                    'payment_reference' =>
                        $paymentReference,
                    'escrow_id' =>
                        $escrowId,
                    'status' =>
                        $afterStatus
                ];
            }


            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' => 'MARK_PAID_FAILED',
                    'escrow_id' => $escrowId,
                    'payment_reference' =>
                        $paymentReference
                ]
            );

            return [
                'success' => false,
                'retry' => true,
                'message' =>
                    'Unable to mark escrow as paid.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | RELOAD ESCROW
        |--------------------------------------------------------------------------
        */

        $escrow =
            $this->escrowModel->find(
                $escrowId
            )
            ?: $escrow;


        /*
        |--------------------------------------------------------------------------
        | VERIFY FINAL STATE
        |--------------------------------------------------------------------------
        */

        $finalStatus = strtolower(
            trim(
                (string)(
                    $escrow['status']
                    ?? ''
                )
            )
        );


        $finalPaymentReference = strtoupper(
            trim(
                (string)(
                    $escrow['payment_reference']
                    ?? ''
                )
            )
        );


        if (
            $finalPaymentReference !== $paymentReference
        ) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'FINAL_PAYMENT_REFERENCE_MISMATCH',
                    'escrow_id' =>
                        $escrowId,
                    'expected' =>
                        $paymentReference,
                    'actual' =>
                        $finalPaymentReference
                ]
            );

            return [
                'success' => false,
                'retry' => true,
                'message' =>
                    'Escrow payment reference could not be confirmed.'
            ];
        }


        if (
            !in_array(
                $finalStatus,
                [
                    'paid',
                    'item_sent',
                    'awaiting_payout',
                    'buyer_confirmed',
                    'completed'
                ],
                true
            )
        ) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'FINAL_STATUS_NOT_PAID',
                    'escrow_id' =>
                        $escrowId,
                    'status' =>
                        $finalStatus
                ]
            );

            return [
                'success' => false,
                'retry' => true,
                'message' =>
                    'Escrow payment state could not be confirmed.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | NOTIFICATION MESSAGES
        |--------------------------------------------------------------------------
        */

        $buyerMessage =
            "✅ Payment received successfully.\n\n"
            .
            "Escrow Reference: "
            .
            $escrowReference
            .
            "\n\n"
            .
            "Your money is now secured in escrow.\n"
            .
            "The seller has been notified to deliver your item.\n\n"
            .
            "When you receive the item, reply:\n"
            .
            "RECEIVED "
            .
            $escrowReference;


        $sellerMessage =
            "💰 Buyer payment received successfully.\n\n"
            .
            "Escrow Reference: "
            .
            $escrowReference
            .
            "\n\n"
            .
            "The buyer's payment is secured in escrow.\n"
            .
            "Please deliver/ship the item.\n\n"
            .
            "After dispatching, reply:\n"
            .
            "SHIP "
            .
            $escrowReference;


        /*
        |--------------------------------------------------------------------------
        | QUEUE NOTIFICATIONS
        |--------------------------------------------------------------------------
        */

        try {

            $this->queueNotifications(
                $escrow,
                $paymentReference,
                $buyerMessage,
                $sellerMessage
            );

        } catch (Throwable $notificationException) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'QUEUE_NOTIFICATION_EXCEPTION',
                    'escrow_id' =>
                        $escrowId,
                    'payment_reference' =>
                        $paymentReference,
                    'message' =>
                        $notificationException->getMessage()
                ]
            );
        }


        /*
        |--------------------------------------------------------------------------
        | IMMEDIATE NOTIFICATIONS
        |--------------------------------------------------------------------------
        */

        try {

            $this->sendImmediateMessages(
                $escrow,
                $buyerMessage,
                $sellerMessage
            );

        } catch (Throwable $notificationException) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'IMMEDIATE_NOTIFICATION_EXCEPTION',
                    'escrow_id' =>
                        $escrowId,
                    'payment_reference' =>
                        $paymentReference,
                    'message' =>
                        $notificationException->getMessage()
                ]
            );
        }


        /*
        |--------------------------------------------------------------------------
        | COMPLETE
        |--------------------------------------------------------------------------
        */

        Logger::write(
            'paystack_escrow_payment',
            [
                'step' => 'PROCESS_COMPLETE',
                'escrow_id' =>
                    $escrowId,
                'escrow_reference' =>
                    $escrowReference,
                'payment_reference' =>
                    $paymentReference,
                'status' =>
                    $finalStatus
            ]
        );


        return [
            'success' => true,
            'retry' => false,
            'already_processed' => false,
            'message' =>
                'Escrow payment secured successfully.',
            'reference' =>
                $escrowReference,
            'payment_reference' =>
                $paymentReference,
            'escrow_id' =>
                $escrowId,
            'status' =>
                $finalStatus
        ];


    } catch (Throwable $e) {

        Logger::write(
            'paystack_escrow_payment_error',
            [
                'step' => 'PROCESS_EXCEPTION',
                'payment_reference' =>
                    $paymentReference,
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
            'success' => false,
            'retry' => true,
            'message' =>
                'Escrow payment processing failed.'
        ];
    }
}
    /**
     * ---------------------------------------------------------
     * BACKWARD COMPATIBILITY
     * ---------------------------------------------------------
     *
     * Older code may still call:
     *
     * handleSuccessfulPayment()
     *
     * Keep this method temporarily so older callers do not
     * immediately break.
     *
     * IMPORTANT:
     *
     * The transaction passed here is expected to already be
     * verified.
     * ---------------------------------------------------------
     */
    public function handleSuccessfulPayment(
        array $webhookTransaction
    ): array {

        Logger::write(
            'paystack_escrow_payment',
            [
                'step' =>
                    'LEGACY_HANDLE_REDIRECTED_TO_PROCESS',

                'reference' =>
                    $webhookTransaction['reference']
                    ?? null
            ]
        );


        return $this->process(
            $webhookTransaction
        );
    }


    /**
     * ---------------------------------------------------------
     * FIND ESCROW BY REFERENCE
     * ---------------------------------------------------------
     */
    protected function findEscrowByReference(
        string $reference
    ): ?array {

        try {

            /*
            |--------------------------------------------------------------------------
            | Preferred Model Method
            |--------------------------------------------------------------------------
            */

            if (
                method_exists(
                    $this->escrowModel,
                    'findByReference'
                )
            ) {

                $result =
                    $this->escrowModel->findByReference(
                        $reference
                    );


                if (
                    is_array($result)
                    &&
                    !empty($result)
                ) {

                    return $result;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Alternate Model Method
            |--------------------------------------------------------------------------
            */

            if (
                method_exists(
                    $this->escrowModel,
                    'findByEscrowReference'
                )
            ) {

                $result =
                    $this->escrowModel->findByEscrowReference(
                        $reference
                    );


                if (
                    is_array($result)
                    &&
                    !empty($result)
                ) {

                    return $result;
                }
            }


            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'REFERENCE_LOOKUP_METHOD_UNAVAILABLE',

                    'reference' =>
                        $reference
                ]
            );

            return null;

        }
        catch (Throwable $e) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'REFERENCE_LOOKUP_EXCEPTION',

                    'reference' =>
                        $reference,

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine()
                ]
            );

            return null;
        }
    }


    /**
     * ---------------------------------------------------------
     * EXTRACT ESCROW AMOUNT
     * ---------------------------------------------------------
     *
     * Supports the common amount column names used by the
     * escrow implementation.
     *
     * Returns amount in NGN.
     * ---------------------------------------------------------
     */
    protected function extractEscrowAmount(
        array $escrow
    ): float {

    $candidates = [
    'total_amount',
    'amount',
    'escrow_amount',
    'payment_amount',
    'price'
    ];


        foreach (
            $candidates
            as $field
        ) {

            if (
                isset($escrow[$field])
                &&
                is_numeric($escrow[$field])
            ) {

                $amount =
                    (float)$escrow[$field];


                if (
                    $amount > 0
                ) {

                    return $amount;
                }
            }
        }


        return 0.0;
    }


    /**
     * ---------------------------------------------------------
     * DEFAULT CALLBACK URL
     * ---------------------------------------------------------
     */
    protected function defaultCallbackUrl(): string
    {
        /*
        |--------------------------------------------------------------------------
        | Prefer configured callback
        |--------------------------------------------------------------------------
        */

        if (
            defined('PAYSTACK_ESCROW_CALLBACK_URL')
            &&
            trim(
                (string)PAYSTACK_ESCROW_CALLBACK_URL
            ) !== ''
        ) {

            return trim(
                (string)PAYSTACK_ESCROW_CALLBACK_URL
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Generic configured application URL
        |--------------------------------------------------------------------------
        */

        if (
            defined('APP_URL')
            &&
            trim(
                (string)APP_URL
            ) !== ''
        ) {

            return rtrim(
                (string)APP_URL,
                '/'
            )
            .
            '/payment/paystack/escrow/callback';
        }


        /*
        |--------------------------------------------------------------------------
        | Final Fallback
        |--------------------------------------------------------------------------
        */

        return
            'https://pingcheckout.com/payment/paystack/escrow/callback';
    }


    /**
     * ---------------------------------------------------------
     * QUEUE NOTIFICATIONS
     * ---------------------------------------------------------
     */
    protected function queueNotifications(
        array $escrow,
        string $paymentReference,
        string $buyerMessage,
        string $sellerMessage
    ): void {

        try {

            $botNotification =
                new BotNotification();


            /*
            |--------------------------------------------------------------------------
            | Buyer
            |--------------------------------------------------------------------------
            */

            if (
                !empty($escrow['buyer_id'])
                &&
                !$botNotification->exists(
                    (int)$escrow['buyer_id'],
                    'escrow_paid',
                    $paymentReference
                )
            ) {

                $botNotification->create(
                    (int)$escrow['buyer_id'],
                    'escrow_paid',
                    'Escrow Payment Received',
                    $buyerMessage,
                    $paymentReference
                );


                Logger::write(
                    'paystack_escrow_payment',
                    [
                        'step' =>
                            'BUYER_NOTIFICATION_CREATED',

                        'buyer_id' =>
                            $escrow['buyer_id']
                    ]
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Seller
            |--------------------------------------------------------------------------
            */

            if (
                !empty($escrow['seller_id'])
                &&
                !$botNotification->exists(
                    (int)$escrow['seller_id'],
                    'escrow_paid',
                    $paymentReference
                )
            ) {

                $botNotification->create(
                    (int)$escrow['seller_id'],
                    'escrow_paid',
                    'Buyer Payment Received',
                    $sellerMessage,
                    $paymentReference
                );


                Logger::write(
                    'paystack_escrow_payment',
                    [
                        'step' =>
                            'SELLER_NOTIFICATION_CREATED',

                        'seller_id' =>
                            $escrow['seller_id']
                    ]
                );
            }

        }
        catch (Throwable $e) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'QUEUE_NOTIFICATION_FAILED',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine()
                ]
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * SEND IMMEDIATE MESSAGES
     * ---------------------------------------------------------
     */
    protected function sendImmediateMessages(
        array $escrow,
        string $buyerMessage,
        string $sellerMessage
    ): void {

        try {

            $userModel =
                new User();


            /*
            |--------------------------------------------------------------------------
            | Buyer
            |--------------------------------------------------------------------------
            */

            if (
                !empty($escrow['buyer_id'])
            ) {

                $buyer =
                    $userModel->find(
                        (int)$escrow['buyer_id']
                    );


                if (
                    is_array($buyer)
                ) {

                    $platform =
                        trim(
                            (string)(
                                $buyer['platform']
                                ?? ''
                            )
                        );

                    $platformId =
                        trim(
                            (string)(
                                $buyer['platform_id']
                                ?? ''
                            )
                        );


                    if (
                        $platform !== ''
                        &&
                        $platformId !== ''
                    ) {

                        $buyerReply =
                            ReplyFactory::make(
                                $platform
                            );


                        $buyerReply->text(
                            $platformId,
                            $buyerMessage
                        );


                        Logger::write(
                            'paystack_escrow_payment',
                            [
                                'step' =>
                                    'BUYER_MESSAGE_SENT',

                                'buyer_id' =>
                                    $escrow['buyer_id'],

                                'platform' =>
                                    $platform
                            ]
                        );
                    }
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Seller
            |--------------------------------------------------------------------------
            */

            if (
                !empty($escrow['seller_id'])
            ) {

                $seller =
                    $userModel->find(
                        (int)$escrow['seller_id']
                    );


                if (
                    is_array($seller)
                ) {

                    $platform =
                        trim(
                            (string)(
                                $seller['platform']
                                ?? ''
                            )
                        );

                    $platformId =
                        trim(
                            (string)(
                                $seller['platform_id']
                                ?? ''
                            )
                        );


                    if (
                        $platform !== ''
                        &&
                        $platformId !== ''
                    ) {

                        $sellerReply =
                            ReplyFactory::make(
                                $platform
                            );


                        $sellerReply->text(
                            $platformId,
                            $sellerMessage
                        );


                        Logger::write(
                            'paystack_escrow_payment',
                            [
                                'step' =>
                                    'SELLER_MESSAGE_SENT',

                                'seller_id' =>
                                    $escrow['seller_id'],

                                'platform' =>
                                    $platform
                            ]
                        );
                    }
                }
            }

        }
        catch (Throwable $e) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'IMMEDIATE_MESSAGE_FAILED',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine()
                ]
            );
        }
    }
}