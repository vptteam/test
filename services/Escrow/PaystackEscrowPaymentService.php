<?php

declare(strict_types=1);

namespace Services\Escrow;

use Core\Logger;
use Core\ReplyFactory;
use Models\BotNotification;
use Models\Escrow;
use Models\User;
use Throwable;

class PaystackEscrowPaymentService
{
    protected Escrow $escrowModel;

    protected EscrowService $escrowService;

    /**
     * Statuses that mean the escrow payment has already
     * successfully moved beyond pending.
     */
    protected array $paidStatuses = [
        'paid',
        'item_sent',
        'awaiting_payout',
        'buyer_confirmed',
        'completed',
    ];


    /**
     * ---------------------------------------------------------
     * CONSTRUCTOR
     * ---------------------------------------------------------
     */
    public function __construct()
    {
        $this->escrowModel =
            new Escrow();

        $this->escrowService =
            new EscrowService();

        Logger::write(
            'paystack_escrow_payment',
            [
                'step' =>
                    'CONSTRUCTOR',
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * INITIALIZE ESCROW PAYMENT
     * ---------------------------------------------------------
     *
     * EscrowService owns:
     *
     * - escrow lookup
     * - escrow status validation
     * - amount validation
     * - fee calculation
     * - Paystack initialization
     * - payment reference generation
     * - Paystack metadata
     *
     * This class deliberately does NOT duplicate those rules.
     *
     * ---------------------------------------------------------
     */
    public function initialize(
        string $reference,
        string $email,
        ?string $callbackUrl = null
    ): array {

        $reference =
            strtoupper(
                trim($reference)
            );

        $email =
            trim($email);

        $callbackUrl = trim((string)($callbackUrl ?? ''));

        if ($callbackUrl === '' && defined('PAYSTACK_ESCROW_CALLBACK_URL')) {
            $callbackUrl = trim((string)PAYSTACK_ESCROW_CALLBACK_URL);
        }


        try {

            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'INITIALIZE_START',

                    'escrow_reference' =>
                        $reference,

                    'email' =>
                        $email,

                    'callback_url' =>
                        $callbackUrl,
                ]
            );


            if ($reference === '') {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_REFERENCE_MISSING',
                    ]
                );

                return [
                    'success' => false,

                    'message' =>
                        'Escrow reference is required.',
                ];
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
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_EMAIL_INVALID',

                        'reference' =>
                            $reference,

                        'email' =>
                            $email,
                    ]
                );

                return [
                    'success' => false,

                    'message' =>
                        'A valid email address is required.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Delegate payment initialization
            |--------------------------------------------------------------------------
            |
            | EscrowService::initializePayment() is the single owner
            | of the Paystack initialization flow.
            |
            */

            $result =
                $this->escrowService
                    ->initializePayment(
                        $reference,
                        $email,
                        $callbackUrl
                    );


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'INITIALIZE_RESULT',

                    'escrow_reference' =>
                        $reference,

                    'success' =>
                        $result['success']
                        ?? false,

                    'payment_reference' =>
                        $result['payment_reference']
                        ?? null,
                ]
            );


            if (
                !is_array($result)
                ||
                !($result['success'] ?? false)
            ) {

                return [
                    'success' =>
                        false,

                    'message' =>
                        $result['message']
                        ??
                        'Unable to initialize escrow payment.',

                    'reference' =>
                        $reference,

                    'payment_reference' =>
                        $result['payment_reference']
                        ?? null,

                    'raw' =>
                        $result['raw']
                        ?? null,
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Normalize Result
            |--------------------------------------------------------------------------
            */

            return [
                'success' =>
                    true,

                'message' =>
                    $result['message']
                    ??
                    'Escrow payment initialized successfully.',

                'reference' =>
                    $result['reference']
                    ??
                    $reference,

                'payment_reference' =>
                    $result['payment_reference']
                    ??
                    $result['reference']
                    ??
                    null,

                'paystack_reference' =>
                    $result['payment_reference']
                    ??
                    $result['reference']
                    ??
                    null,

                'escrow_id' =>
                    $result['escrow_id']
                    ??
                    ($result['escrow']['id'] ?? null),

                'amount' =>
                    $result['amount']
                    ?? null,

                'escrow_fee' =>
                    $result['escrow_fee']
                    ?? null,

                'paystack_fee' =>
                    $result['paystack_fee']
                    ?? null,

                'total' =>
                    $result['total']
                    ?? null,

                'currency' =>
                    $result['currency']
                    ??
                    'NGN',

                'authorization_url' =>
                    $result['authorization_url']
                    ?? null,

                'access_code' =>
                    $result['access_code']
                    ?? null,

                'escrow' =>
                    $result['escrow']
                    ?? null,

                'raw' =>
                    $result['raw']
                    ?? null,
            ];


        } catch (Throwable $e) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'INITIALIZE_EXCEPTION',

                    'reference' =>
                        $reference,

                    'email' =>
                        $email,

                    'callback_url' =>
                        $callbackUrl,

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


            return [
                'success' =>
                    false,

                'message' =>
                    'Unable to initialize escrow payment.',
            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * PROCESS VERIFIED PAYSTACK PAYMENT
     * ---------------------------------------------------------
     *
     * IMPORTANT:
     *
     * The transaction passed here MUST already have been
     * verified by PaystackGateway::verify().
     *
     * This method does NOT call Paystack.
     *
     * Flow:
     *
     * verified transaction
     *       ↓
     * validate status
     *       ↓
     * validate escrow metadata
     *       ↓
     * locate escrow
     *       ↓
     * validate references
     *       ↓
     * validate amount
     *       ↓
     * validate currency
     *       ↓
     * markPaid()
     *       ↓
     * reload escrow
     *       ↓
     * notifications
     *
     * ---------------------------------------------------------
     */
    public function process(
        array $transaction
    ): array {

        $paymentReference =
            strtoupper(
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
                    'step' =>
                        'PROCESS_START',

                    'payment_reference' =>
                        $paymentReference,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Transaction Validation
            |--------------------------------------------------------------------------
            */

            if (
                $transaction === []
            ) {

                return [
                    'success' => false,

                    'retry' => false,

                    'message' =>
                        'Verified payment data is unavailable.',
                ];
            }


            if (
                $paymentReference === ''
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PROCESS_REFERENCE_MISSING',
                    ]
                );

                return [
                    'success' => false,

                    'retry' => false,

                    'message' =>
                        'Payment reference is missing.',
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
                            $transaction['status']
                            ?? ''
                        )
                    )
                );


            if (
                $paymentStatus !== 'success'
            ) {

                Logger::write(
                    'paystack_escrow_payment',
                    [
                        'step' =>
                            'PAYMENT_NOT_SUCCESSFUL',

                        'payment_reference' =>
                            $paymentReference,

                        'status' =>
                            $paymentStatus,
                    ]
                );

                return [
                    'success' => false,

                    'retry' => false,

                    'message' =>
                        'Payment is not successful.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Metadata
            |--------------------------------------------------------------------------
            */

            $metadata =
                $transaction['metadata']
                ??
                [];


            if (
                !is_array($metadata)
            ) {
                $metadata = [];
            }


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'PROCESS_METADATA',

                    'payment_reference' =>
                        $paymentReference,

                    'metadata' =>
                        $metadata,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Payment Type
            |--------------------------------------------------------------------------
            |
            | Accept the formats currently used by the application:
            |
            | type = escrow
            | type = escrow_payment
            | payment_type = escrow
            |
            */

            $type =
                strtolower(
                    trim(
                        (string)(
                            $metadata['type']
                            ?? ''
                        )
                    )
                );


            $paymentType =
                strtolower(
                    trim(
                        (string)(
                            $metadata['payment_type']
                            ?? ''
                        )
                    )
                );


            $isEscrowPayment =
                $type === 'escrow'
                ||
                $type === 'escrow_payment'
                ||
                $paymentType === 'escrow';


            if (
                !$isEscrowPayment
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INVALID_PAYMENT_TYPE',

                        'payment_reference' =>
                            $paymentReference,

                        'type' =>
                            $type,

                        'payment_type' =>
                            $paymentType,
                    ]
                );

                return [
                    'success' => false,

                    'retry' => false,

                    'message' =>
                        'This Paystack transaction is not an escrow payment.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Escrow ID
            |--------------------------------------------------------------------------
            */

            $escrowId =
                (int)(
                    $metadata['escrow_id']
                    ?? 0
                );


            /*
            |--------------------------------------------------------------------------
            | Escrow Reference From Metadata
            |--------------------------------------------------------------------------
            */

            $metadataEscrowReference =
                strtoupper(
                    trim(
                        (string)(
                            $metadata['escrow_reference']
                            ??
                            $metadata['escrow_ref']
                            ??
                            $metadata['reference']
                            ??
                            ''
                        )
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | Locate Escrow
            |--------------------------------------------------------------------------
            |
            | ID is preferred.
            |
            | Reference is the fallback.
            |
            */

            $escrow = null;


            if (
                $escrowId > 0
            ) {

                Logger::write(
                    'paystack_escrow_payment',
                    [
                        'step' =>
                            'ESCROW_LOOKUP_BY_ID',

                        'escrow_id' =>
                            $escrowId,

                        'payment_reference' =>
                            $paymentReference,
                    ]
                );


                $escrow =
                    $this->escrowModel
                        ->find(
                            $escrowId
                        );
            }


            if (
                !is_array($escrow)
                ||
                empty($escrow)
            ) {

                if (
                    $metadataEscrowReference !== ''
                ) {

                    Logger::write(
                        'paystack_escrow_payment',
                        [
                            'step' =>
                                'ESCROW_LOOKUP_BY_REFERENCE',

                            'escrow_reference' =>
                                $metadataEscrowReference,

                            'payment_reference' =>
                                $paymentReference,
                        ]
                    );


                    $escrow =
                        $this->escrowModel
                            ->findByReference(
                                $metadataEscrowReference
                            );
                }
            }


            if (
                !is_array($escrow)
                ||
                empty($escrow)
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'ESCROW_NOT_FOUND',

                        'escrow_id' =>
                            $escrowId,

                        'escrow_reference' =>
                            $metadataEscrowReference,

                        'payment_reference' =>
                            $paymentReference,
                    ]
                );

                return [
                    'success' => false,

                    'retry' => false,

                    'message' =>
                        'Escrow transaction not found.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Actual Escrow ID
            |--------------------------------------------------------------------------
            */

            $escrowId =
                (int)(
                    $escrow['id']
                    ?? 0
                );


            if (
                $escrowId <= 0
            ) {

                return [
                    'success' => false,

                    'retry' => false,

                    'message' =>
                        'Invalid escrow transaction.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Escrow Reference
            |--------------------------------------------------------------------------
            */

            $escrowReference =
                strtoupper(
                    trim(
                        (string)(
                            $escrow['reference']
                            ?? ''
                        )
                    )
                );


            if (
                $escrowReference === ''
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'ESCROW_REFERENCE_MISSING',

                        'escrow_id' =>
                            $escrowId,

                        'payment_reference' =>
                            $paymentReference,
                    ]
                );

                return [
                    'success' => false,

                    'retry' => false,

                    'message' =>
                        'Escrow reference is missing.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Metadata Reference Validation
            |--------------------------------------------------------------------------
            */

            if (
                $metadataEscrowReference !== ''
                &&
                !hash_equals(
                    $escrowReference,
                    $metadataEscrowReference
                )
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'ESCROW_REFERENCE_MISMATCH',

                        'escrow_id' =>
                            $escrowId,

                        'metadata_reference' =>
                            $metadataEscrowReference,

                        'database_reference' =>
                            $escrowReference,

                        'payment_reference' =>
                            $paymentReference,
                    ]
                );

                return [
                    'success' => false,

                    'retry' => false,

                    'message' =>
                        'Escrow reference mismatch.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Payment Reference Validation
            |--------------------------------------------------------------------------
            |
            | If an existing Paystack reference is stored on the
            | escrow, it MUST match the verified transaction.
            |
            */

            $storedPaymentReference =
                strtoupper(
                    trim(
                        (string)(
                            $escrow['payment_reference']
                            ?? ''
                        )
                    )
                );


            if (
                $storedPaymentReference !== ''
                &&
                !hash_equals(
                    $storedPaymentReference,
                    $paymentReference
                )
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PAYMENT_REFERENCE_CONFLICT',

                        'escrow_id' =>
                            $escrowId,

                        'stored_reference' =>
                            $storedPaymentReference,

                        'incoming_reference' =>
                            $paymentReference,
                    ]
                );

                return [
                    'success' => false,

                    'retry' => false,

                    'message' =>
                        'Payment reference does not match escrow payment.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Escrow Status
            |--------------------------------------------------------------------------
            */

            $status =
                $this->normalizeStatus(
                    $escrow['status']
                    ?? ''
                );


            /*
            |--------------------------------------------------------------------------
            | Idempotency
            |--------------------------------------------------------------------------
            |
            | If this transaction has already successfully moved
            | the escrow forward, return success.
            |
            */

            if (
                in_array(
                    $status,
                    $this->paidStatuses,
                    true
                )
            ) {

                Logger::write(
                    'paystack_escrow_payment',
                    [
                        'step' =>
                            'ALREADY_PROCESSED',

                        'escrow_id' =>
                            $escrowId,

                        'escrow_reference' =>
                            $escrowReference,

                        'payment_reference' =>
                            $paymentReference,

                        'status' =>
                            $status,
                    ]
                );


                /*
                * If payment reference is missing on an already
                * advanced escrow, do not silently rewrite it here.
                *
                * The payment state has already been completed.
                */

                return [
                    'success' =>
                        true,

                    'retry' =>
                        false,

                    'already_processed' =>
                        true,

                    'message' =>
                        'Escrow payment has already been processed.',

                    'reference' =>
                        $escrowReference,

                    'payment_reference' =>
                        $paymentReference,

                    'escrow_id' =>
                        $escrowId,

                    'status' =>
                        $status,
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Only Pending Escrows Can Become Paid
            |--------------------------------------------------------------------------
            */

            if (
                $status !== 'pending'
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INVALID_ESCROW_STATUS',

                        'escrow_id' =>
                            $escrowId,

                        'status' =>
                            $status,

                        'payment_reference' =>
                            $paymentReference,
                    ]
                );

                return [
                    'success' => false,

                    'retry' => false,

                    'message' =>
                        'This escrow cannot currently accept payment.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Escrow Amount
            |--------------------------------------------------------------------------
            */

            $escrowAmount =
                $this->extractEscrowAmount(
                    $escrow
                );


            if (
                $escrowAmount <= 0
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'ESCROW_AMOUNT_INVALID',

                        'escrow_id' =>
                            $escrowId,

                        'amount' =>
                            $escrowAmount,
                    ]
                );

                return [
                    'success' => false,

                    'retry' => false,

                    'message' =>
                        'Escrow amount is invalid.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Calculate Expected Buyer Total
            |--------------------------------------------------------------------------
            |
            | EscrowService remains the single owner of fee rules.
            |
            */

            $fees =
                $this->escrowService
                    ->calculateBuyerTotal(
                        $escrowAmount
                    );


            $expectedBuyerTotal =
                (float)(
                    $fees['buyer_total']
                    ?? 0
                );


            if (
                $expectedBuyerTotal <= 0
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'EXPECTED_TOTAL_INVALID',

                        'escrow_id' =>
                            $escrowId,

                        'fees' =>
                            $fees,
                    ]
                );

                return [
                    'success' => false,

                    'retry' => true,

                    'message' =>
                        'Unable to calculate expected escrow payment amount.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Paystack Amount
            |--------------------------------------------------------------------------
            |
            | Paystack returns kobo.
            |
            */

            $receivedAmountKobo =
                (int)(
                    $transaction['amount']
                    ?? 0
                );


            if (
                $receivedAmountKobo <= 0
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PAYSTACK_AMOUNT_INVALID',

                        'escrow_id' =>
                            $escrowId,

                        'payment_reference' =>
                            $paymentReference,

                        'received_kobo' =>
                            $receivedAmountKobo,
                    ]
                );

                return [
                    'success' => false,

                    'retry' => false,

                    'message' =>
                        'Paystack payment amount is invalid.',
                ];
            }


            $expectedAmountKobo =
                $this->toKobo(
                    $expectedBuyerTotal
                );


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'AMOUNT_VALIDATION',

                    'escrow_id' =>
                        $escrowId,

                    'escrow_reference' =>
                        $escrowReference,

                    'payment_reference' =>
                        $paymentReference,

                    'escrow_amount' =>
                        $escrowAmount,

                    'escrow_fee' =>
                        $fees['escrow_fee']
                        ?? 0,

                    'paystack_fee' =>
                        $fees['paystack_fee']
                        ?? 0,

                    'expected_total_ngn' =>
                        $expectedBuyerTotal,

                    'expected_total_kobo' =>
                        $expectedAmountKobo,

                    'received_kobo' =>
                        $receivedAmountKobo,
                ]
            );


            if (
                $expectedAmountKobo
                !==
                $receivedAmountKobo
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'AMOUNT_MISMATCH',

                        'escrow_id' =>
                            $escrowId,

                        'payment_reference' =>
                            $paymentReference,

                        'expected_kobo' =>
                            $expectedAmountKobo,

                        'received_kobo' =>
                            $receivedAmountKobo,
                    ]
                );

                return [
                    'success' => false,

                    'retry' => false,

                    'message' =>
                        'Payment amount does not match escrow payment total.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Currency
            |--------------------------------------------------------------------------
            */

            $paymentCurrency =
                strtoupper(
                    trim(
                        (string)(
                            $transaction['currency']
                            ?? ''
                        )
                    )
                );


            $escrowCurrency =
                strtoupper(
                    trim(
                        (string)(
                            $escrow['currency']
                            ?? 'NGN'
                        )
                    )
                );


            if (
                $escrowCurrency === ''
            ) {
                $escrowCurrency = 'NGN';
            }


            if (
                $paymentCurrency === ''
            ) {
                $paymentCurrency = 'NGN';
            }


            if (
                $escrowCurrency !== 'NGN'
                ||
                $paymentCurrency !== 'NGN'
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'CURRENCY_MISMATCH',

                        'escrow_id' =>
                            $escrowId,

                        'escrow_currency' =>
                            $escrowCurrency,

                        'payment_currency' =>
                            $paymentCurrency,
                    ]
                );

                return [
                    'success' => false,

                    'retry' => false,

                    'message' =>
                        'Only matching NGN escrow payments are accepted.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | MARK ESCROW PAID
            |--------------------------------------------------------------------------
            |
            | This is the ONLY state-transition call made here.
            |
            */

            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'MARK_PAID_START',

                    'escrow_id' =>
                        $escrowId,

                    'escrow_reference' =>
                        $escrowReference,

                    'payment_reference' =>
                        $paymentReference,
                ]
            );


            $markedPaid =
                $this->escrowModel
                    ->markPaid(
                        $escrowId,
                        $paymentReference
                    );


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'MARK_PAID_RESULT',

                    'escrow_id' =>
                        $escrowId,

                    'payment_reference' =>
                        $paymentReference,

                    'result' =>
                        $markedPaid,
                ]
            );


            if (
                $markedPaid !== true
            ) {

                /*
                |--------------------------------------------------------------------------
                | Concurrency Recovery
                |--------------------------------------------------------------------------
                |
                | Another webhook request may have won the race.
                |
                | Reload the escrow and determine its actual state.
                |
                */

                $after =
                    $this->escrowModel
                        ->find(
                            $escrowId
                        );


                if (
                    is_array($after)
                    &&
                    in_array(
                        $this->normalizeStatus(
                            $after['status']
                            ?? ''
                        ),
                        $this->paidStatuses,
                        true
                    )
                    &&
                    strtoupper(
                        trim(
                            (string)(
                                $after['payment_reference']
                                ?? ''
                            )
                        )
                    ) ===
                    $paymentReference
                ) {

                    Logger::write(
                        'paystack_escrow_payment',
                        [
                            'step' =>
                                'MARK_PAID_CONCURRENTLY_CONFIRMED',

                            'escrow_id' =>
                                $escrowId,

                            'payment_reference' =>
                                $paymentReference,

                            'status' =>
                                $after['status']
                                ?? null,
                        ]
                    );


                    return [
                        'success' =>
                            true,

                        'retry' =>
                            false,

                        'already_processed' =>
                            true,

                        'message' =>
                            'Escrow payment has already been processed.',

                        'reference' =>
                            $escrowReference,

                        'payment_reference' =>
                            $paymentReference,

                        'escrow_id' =>
                            $escrowId,

                        'status' =>
                            $this->normalizeStatus(
                                $after['status']
                                ?? 'paid'
                            ),
                    ];
                }


                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'MARK_PAID_FAILED',

                        'escrow_id' =>
                            $escrowId,

                        'payment_reference' =>
                            $paymentReference,
                    ]
                );


                return [
                    'success' =>
                        false,

                    'retry' =>
                        true,

                    'message' =>
                        'Unable to mark escrow as paid.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Reload Authoritative Escrow
            |--------------------------------------------------------------------------
            */

            $escrow =
                $this->escrowModel
                    ->find(
                        $escrowId
                    )
                ??
                $escrow;


            $finalStatus =
                $this->normalizeStatus(
                    $escrow['status']
                    ?? 'paid'
                );


            $finalPaymentReference =
                strtoupper(
                    trim(
                        (string)(
                            $escrow['payment_reference']
                            ??
                            $paymentReference
                        )
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | Confirm Payment State
            |--------------------------------------------------------------------------
            */

            if (
                !in_array(
                    $finalStatus,
                    $this->paidStatuses,
                    true
                )
                ||
                $finalPaymentReference !==
                    $paymentReference
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PAYMENT_STATE_CONFIRMATION_FAILED',

                        'escrow_id' =>
                            $escrowId,

                        'payment_reference' =>
                            $paymentReference,

                        'final_status' =>
                            $finalStatus,

                        'final_payment_reference' =>
                            $finalPaymentReference,
                    ]
                );


                return [
                    'success' =>
                        false,

                    'retry' =>
                        true,

                    'message' =>
                        'Escrow payment state could not be confirmed.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Build Messages
            |--------------------------------------------------------------------------
            */

            $buyerMessage =
                "Your payment has been received.\n\n"
                .
                "The seller has been notified immediately.\n\n"
                .
                "After you receive your item, reply:\n\n"
                .
                "RECEIVED "
                .
                $escrowReference;


            $sellerMessage =
                "A buyer has successfully paid.\n\n"
                .
                "Escrow Reference:\n"
                .
                $escrowReference
                .
                "\n\n"
                .
                "Please deliver the item to the buyer.\n\n"
                .
                "After dispatching the item reply:\n\n"
                .
                "SHIP "
                .
                $escrowReference;


            /*
            |--------------------------------------------------------------------------
            | Queue Notifications
            |--------------------------------------------------------------------------
            |
            | Notification failure MUST NOT undo a successful payment.
            |
            */

            $this->queueNotifications(
                $escrow,
                $paymentReference,
                $buyerMessage,
                $sellerMessage
            );


            /*
            |--------------------------------------------------------------------------
            | Immediate Messages
            |--------------------------------------------------------------------------
            */

            $this->sendImmediateMessages(
                $escrow,
                $buyerMessage,
                $sellerMessage
            );


            /*
            |--------------------------------------------------------------------------
            | Complete
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'PROCESS_COMPLETE',

                    'escrow_id' =>
                        $escrowId,

                    'escrow_reference' =>
                        $escrowReference,

                    'payment_reference' =>
                        $paymentReference,

                    'status' =>
                        $finalStatus,
                ]
            );


            return [
                'success' =>
                    true,

                'retry' =>
                    false,

                'already_processed' =>
                    false,

                'message' =>
                    'Escrow payment secured successfully.',

                'reference' =>
                    $escrowReference,

                'payment_reference' =>
                    $paymentReference,

                'escrow_id' =>
                    $escrowId,

                'status' =>
                    $finalStatus,
            ];


        } catch (Throwable $e) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'PROCESS_EXCEPTION',

                    'payment_reference' =>
                        $paymentReference,

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


            return [
                'success' =>
                    false,

                'retry' =>
                    true,

                'message' =>
                    'Escrow payment processing failed.',
            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * BACKWARD COMPATIBILITY
     * ---------------------------------------------------------
     */
    public function handleSuccessfulPayment(
        array $transaction
    ): array {

        Logger::write(
            'paystack_escrow_payment',
            [
                'step' =>
                    'LEGACY_HANDLER_REDIRECT',

                'reference' =>
                    $transaction['reference']
                    ?? null,
            ]
        );


        return $this->process(
            $transaction
        );
    }


    /**
     * ---------------------------------------------------------
     * EXTRACT ESCROW AMOUNT
     * ---------------------------------------------------------
     *
     * EscrowService owns fee calculation.
     * This method only extracts the base escrow amount.
     *
     * ---------------------------------------------------------
     */
    protected function extractEscrowAmount(
        array $escrow
    ): float {

        $fields = [
            'amount',
            'escrow_amount',
            'payment_amount',
            'total_amount',
            'price',
        ];


        foreach (
            $fields as $field
        ) {

            if (
                !array_key_exists(
                    $field,
                    $escrow
                )
            ) {
                continue;
            }


            $value =
                $escrow[$field];


            if (
                !is_numeric($value)
            ) {
                continue;
            }


            $amount =
                (float)$value;


            if (
                $amount > 0
            ) {

                return round(
                    $amount,
                    2
                );
            }
        }


        return 0.0;
    }


    /**
     * ---------------------------------------------------------
     * NORMALIZE STATUS
     * ---------------------------------------------------------
     */
    protected function normalizeStatus(
        mixed $status
    ): string {

        return strtolower(
            trim(
                (string)$status
            )
        );
    }


    /**
     * ---------------------------------------------------------
     * NGN TO KOBO
     * ---------------------------------------------------------
     */
    protected function toKobo(
        float $amount
    ): int {

        return (int)round(
            $amount * 100
        );
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

            $notification =
                new BotNotification();


            /*
            |--------------------------------------------------------------------------
            | Buyer
            |--------------------------------------------------------------------------
            */

            $buyerId =
                (int)(
                    $escrow['buyer_id']
                    ?? 0
                );


            if (
                $buyerId > 0
            ) {

                $exists =
                    $notification->exists(
                        $buyerId,
                        'escrow_paid',
                        $paymentReference
                    );


                if (
                    !$exists
                ) {

                    $notification->create(
                        $buyerId,
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
                                $buyerId,

                            'payment_reference' =>
                                $paymentReference,
                        ]
                    );
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Seller
            |--------------------------------------------------------------------------
            */

            $sellerId =
                (int)(
                    $escrow['seller_id']
                    ?? 0
                );


            if (
                $sellerId > 0
            ) {

                $exists =
                    $notification->exists(
                        $sellerId,
                        'escrow_paid',
                        $paymentReference
                    );


                if (
                    !$exists
                ) {

                    $notification->create(
                        $sellerId,
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
                                $sellerId,

                            'payment_reference' =>
                                $paymentReference,
                        ]
                    );
                }
            }


        } catch (Throwable $e) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'QUEUE_NOTIFICATION_FAILED',

                    'payment_reference' =>
                        $paymentReference,

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
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

            $buyerId =
                (int)(
                    $escrow['buyer_id']
                    ?? 0
                );


            if (
                $buyerId > 0
            ) {

                $buyer =
                    $userModel->find(
                        $buyerId
                    );


                $this->sendUserMessage(
                    $buyer,
                    $buyerMessage,
                    'BUYER_MESSAGE'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Seller
            |--------------------------------------------------------------------------
            */

            $sellerId =
                (int)(
                    $escrow['seller_id']
                    ?? 0
                );


            if (
                $sellerId > 0
            ) {

                $seller =
                    $userModel->find(
                        $sellerId
                    );


                $this->sendUserMessage(
                    $seller,
                    $sellerMessage,
                    'SELLER_MESSAGE'
                );
            }


        } catch (Throwable $e) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'IMMEDIATE_MESSAGES_FAILED',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * SEND USER MESSAGE
     * ---------------------------------------------------------
     */
    protected function sendUserMessage(
        mixed $user,
        string $message,
        string $logStep
    ): void {

        if (
            !is_array($user)
            ||
            empty($user)
        ) {

            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        $logStep
                        .
                        '_USER_NOT_FOUND',
                ]
            );

            return;
        }


        $platform =
            strtolower(
                trim(
                    (string)(
                        $user['platform']
                        ?? ''
                    )
                )
            );


        $platformId =
            trim(
                (string)(
                    $user['platform_id']
                    ??
                    $user['external_user_id']
                    ??
                    ''
                )
            );


        if (
            $platform === ''
            ||
            $platformId === ''
        ) {

            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        $logStep
                        .
                        '_PLATFORM_DATA_MISSING',

                    'user_id' =>
                        $user['id']
                        ?? null,

                    'platform' =>
                        $platform,
                ]
            );

            return;
        }


        try {

            $reply =
                ReplyFactory::make(
                    $platform
                );


            $reply->text(
                $platformId,
                $message
            );


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        $logStep
                        .
                        '_SENT',

                    'user_id' =>
                        $user['id']
                        ?? null,

                    'platform' =>
                        $platform,

                    'platform_id' =>
                        $platformId,
                ]
            );


        } catch (Throwable $e) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        $logStep
                        .
                        '_FAILED',

                    'user_id' =>
                        $user['id']
                        ?? null,

                    'platform' =>
                        $platform,

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );
        }
    }
}