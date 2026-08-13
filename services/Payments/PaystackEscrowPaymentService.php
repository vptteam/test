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


    /**
     * ---------------------------------------------------------
     * Constructor
     * ---------------------------------------------------------
     */
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
     * Initialize Escrow Payment
     * ---------------------------------------------------------
     *
     * Flow:
     *
     * Escrow reference
     *      ↓
     * Load escrow
     *      ↓
     * Validate pending status
     *      ↓
     * Read authoritative total_amount
     *      ↓
     * Build metadata
     *      ↓
     * Paystack initialize
     *      ↓
     * Return authorization URL
     *
     * IMPORTANT:
     *
     * The escrow reference is also the Paystack reference.
     *
     * Example:
     *
     * ESC000123
     *
     * This keeps initialization, verification and webhook
     * processing deterministic.
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

        try {

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

            if (
                $reference === ''
            ) {

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
            | Find Escrow
            |--------------------------------------------------------------------------
            */

            $escrow =
                $this->findEscrowByReference(
                    $reference
                );


            if (
                !is_array($escrow)
                ||
                empty($escrow)
            ) {

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


            /*
            |--------------------------------------------------------------------------
            | Escrow ID
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
            | Verify Database Reference
            |--------------------------------------------------------------------------
            */

            $databaseReference =
                strtoupper(
                    trim(
                        (string)(
                            $escrow['reference']
                            ?? ''
                        )
                    )
                );


            if (
                $databaseReference === ''
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_DATABASE_REFERENCE_MISSING',

                        'escrow_id' =>
                            $escrowId
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Escrow reference is missing.'
                ];
            }


            if (
                !hash_equals(
                    $databaseReference,
                    $reference
                )
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_REFERENCE_MISMATCH',

                        'requested_reference' =>
                            $reference,

                        'database_reference' =>
                            $databaseReference,

                        'escrow_id' =>
                            $escrowId
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Escrow reference mismatch.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Escrow Status
            |--------------------------------------------------------------------------
            */

            $status =
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
                        'INITIALIZE_ESCROW_STATUS',

                    'escrow_id' =>
                        $escrowId,

                    'reference' =>
                        $reference,

                    'status' =>
                        $status
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Prevent Duplicate Payment
            |--------------------------------------------------------------------------
            */

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

                Logger::write(
                    'paystack_escrow_payment',
                    [
                        'step' =>
                            'INITIALIZE_ALREADY_PROCESSED',

                        'escrow_id' =>
                            $escrowId,

                        'reference' =>
                            $reference,

                        'status' =>
                            $status
                    ]
                );

                return [
                    'success' => false,

                    'already_processed' => true,

                    'message' =>
                        'This escrow payment has already been processed.',

                    'reference' =>
                        $reference,

                    'escrow_id' =>
                        $escrowId,

                    'status' =>
                        $status
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Only Pending Escrows Can Accept Payment
            |--------------------------------------------------------------------------
            */

            if (
                $status !== 'pending'
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_INVALID_STATUS',

                        'escrow_id' =>
                            $escrowId,

                        'reference' =>
                            $reference,

                        'status' =>
                            $status
                    ]
                );

                return [
                    'success' => false,

                    'message' =>
                        'This escrow cannot currently accept payment.',

                    'reference' =>
                        $reference,

                    'status' =>
                        $status
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Determine Authoritative Amount
            |--------------------------------------------------------------------------
            |
            | total_amount = amount actually charged to buyer.
            |
            | This is what Paystack must receive.
            |
            */

            $amount =
                $this->extractEscrowAmount(
                    $escrow
                );


            if (
                $amount <= 0
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_AMOUNT_INVALID',

                        'escrow_id' =>
                            $escrowId,

                        'reference' =>
                            $reference,

                        'amount' =>
                            $amount
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
            | Callback URL
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

            $callbackUrl =
                trim($callbackUrl);


            if (
                $callbackUrl === ''
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_CALLBACK_MISSING',

                        'reference' =>
                            $reference
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Payment callback URL is not configured.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Payment Metadata
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | type MUST be "escrow"
            |
            | because process() uses this to identify escrow payments.
            |
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


            /*
            |--------------------------------------------------------------------------
            | Optional Metadata
            |--------------------------------------------------------------------------
            */

            if (
                isset($escrow['listing_id'])
            ) {

                $metadata['listing_id'] =
                    $escrow['listing_id'];
            }


            if (
                isset($escrow['buyer_id'])
            ) {

                $metadata['buyer_id'] =
                    $escrow['buyer_id'];
            }


            if (
                isset($escrow['seller_id'])
            ) {

                $metadata['seller_id'] =
                    $escrow['seller_id'];
            }


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'INITIALIZE_PAYSTACK_REQUEST',

                    'escrow_id' =>
                        $escrowId,

                    'reference' =>
                        $reference,

                    'amount_ngn' =>
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
            | Paystack Reference
            |--------------------------------------------------------------------------
            |
            | ONE reference throughout the entire payment lifecycle.
            |
            | Escrow reference:
            |
            |     ESC000123
            |
            | Paystack reference:
            |
            |     ESC000123
            |
            | Webhook reference:
            |
            |     ESC000123
            |
            | markPaid():
            |
            |     ESC000123
            |
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
                    (int)round($amount),
                    $email,
                    $paystackReference,
                    $callbackUrl,
                    $metadata
                );


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'INITIALIZE_PAYSTACK_RESULT',

                    'escrow_id' =>
                        $escrowId,

                    'reference' =>
                        $reference,

                    'result_success' =>
                        $result['success']
                        ?? false,

                    'paystack_reference' =>
                        $result['reference']
                        ?? null,

                    'authorization_url' =>
                        $result['authorization_url']
                        ?? null
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Paystack Initialization Failed
            |--------------------------------------------------------------------------
            */

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
                        'Unable to initialize escrow payment.',

                    'reference' =>
                        $reference,

                    'raw' =>
                        $result['raw']
                        ?? null
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Verify Returned Reference
            |--------------------------------------------------------------------------
            */

            $returnedReference =
                strtoupper(
                    trim(
                        (string)(
                            $result['reference']
                            ??
                            $paystackReference
                        )
                    )
                );


            if (
                $returnedReference === ''
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_PAYSTACK_REFERENCE_MISSING',

                        'reference' =>
                            $reference,

                        'result' =>
                            $result
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Paystack did not return a transaction reference.'
                ];
            }


            if (
                !hash_equals(
                    $reference,
                    $returnedReference
                )
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_PAYSTACK_REFERENCE_MISMATCH',

                        'escrow_reference' =>
                            $reference,

                        'paystack_reference' =>
                            $returnedReference
                    ]
                );

                return [
                    'success' => false,

                    'message' =>
                        'Paystack returned an unexpected payment reference.',

                    'reference' =>
                        $reference,

                    'paystack_reference' =>
                        $returnedReference
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
                        $result['authorization_url']
                        ?? ''
                    )
                );


            if (
                $authorizationUrl === ''
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_AUTHORIZATION_URL_MISSING',

                        'reference' =>
                            $reference,

                        'result' =>
                            $result
                    ]
                );

                return [
                    'success' => false,

                    'message' =>
                        'Paystack did not return a payment link.',

                    'reference' =>
                        $reference
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Complete
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'INITIALIZE_COMPLETE',

                    'escrow_id' =>
                        $escrowId,

                    'reference' =>
                        $reference,

                    'paystack_reference' =>
                        $returnedReference,

                    'amount' =>
                        $amount
                ]
            );


            return [

                'success' =>
                    true,

                'message' =>
                    'Escrow payment initialized successfully.',

                'escrow_id' =>
                    $escrowId,

                'reference' =>
                    $reference,

                'payment_reference' =>
                    $returnedReference,

                'paystack_reference' =>
                    $returnedReference,

                'amount' =>
                    $amount,

                'currency' =>
                    'NGN',

                'authorization_url' =>
                    $authorizationUrl,

                'access_code' =>
                    $result['access_code']
                    ?? null,

                'escrow' =>
                    $escrow
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
     * Process Verified Paystack Escrow Payment
     * ---------------------------------------------------------
     *
     * IMPORTANT:
     *
     * The caller should already have verified the Paystack
     * transaction using PaystackGateway::verify().
     *
     * This method does NOT call Paystack again.
     *
     * ---------------------------------------------------------
     */
    public function process(
        array $transaction
    ): array {

        $reference =
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

                    'reference' =>
                        $reference
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Validate Transaction
            |--------------------------------------------------------------------------
            */

            if (
                empty($transaction)
            ) {

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Verified payment data is unavailable.'
                ];
            }


            if (
                $reference === ''
            ) {

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Payment reference is missing.'
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
                            'PROCESS_PAYMENT_NOT_SUCCESSFUL',

                        'reference' =>
                            $reference,

                        'status' =>
                            $paymentStatus
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Payment is not successful.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Metadata
            |--------------------------------------------------------------------------
            */

            $metadata =
                $transaction['metadata']
                ?? [];


            if (
                !is_array($metadata)
            ) {

                $metadata = [];
            }


            /*
            |--------------------------------------------------------------------------
            | Payment Type
            |--------------------------------------------------------------------------
            */

            $paymentType =
                strtolower(
                    trim(
                        (string)(
                            $metadata['type']
                            ?? ''
                        )
                    )
                );


            /*
            | Accept legacy "escrow_payment" as well.
            */

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
                        'step' =>
                            'PROCESS_INVALID_PAYMENT_TYPE',

                        'reference' =>
                            $reference,

                        'type' =>
                            $paymentType
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
            | Escrow ID
            |--------------------------------------------------------------------------
            */

            $escrowId =
                (int)(
                    $metadata['escrow_id']
                    ?? 0
                );


            if (
                $escrowId <= 0
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PROCESS_ESCROW_ID_MISSING',

                        'reference' =>
                            $reference,

                        'metadata' =>
                            $metadata
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
            | Load Escrow
            |--------------------------------------------------------------------------
            */

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
                        'step' =>
                            'PROCESS_ESCROW_NOT_FOUND',

                        'escrow_id' =>
                            $escrowId,

                        'reference' =>
                            $reference
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

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Escrow reference is missing.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Verify Payment Reference
            |--------------------------------------------------------------------------
            |
            | Paystack reference MUST equal escrow reference.
            |
            */

            if (
                !hash_equals(
                    $escrowReference,
                    $reference
                )
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PROCESS_REFERENCE_MISMATCH',

                        'payment_reference' =>
                            $reference,

                        'escrow_reference' =>
                            $escrowReference,

                        'escrow_id' =>
                            $escrowId
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Payment reference does not match escrow reference.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Verify Metadata Escrow Reference
            |--------------------------------------------------------------------------
            */

            $metadataReference =
                strtoupper(
                    trim(
                        (string)(
                            $metadata['escrow_reference']
                            ?? ''
                        )
                    )
                );


            if (
                $metadataReference !== ''
                &&
                !hash_equals(
                    $escrowReference,
                    $metadataReference
                )
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PROCESS_METADATA_REFERENCE_MISMATCH',

                        'payment_reference' =>
                            $reference,

                        'metadata_reference' =>
                            $metadataReference,

                        'escrow_reference' =>
                            $escrowReference,

                        'escrow_id' =>
                            $escrowId
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
            | Amount Validation
            |--------------------------------------------------------------------------
            */

            $escrowAmount =
                $this->extractEscrowAmount(
                    $escrow
                );


            $paystackAmountKobo =
                (int)(
                    $transaction['amount']
                    ?? 0
                );


            if (
                $escrowAmount <= 0
            ) {

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Escrow amount is invalid.'
                ];
            }


            if (
                $paystackAmountKobo <= 0
            ) {

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Paystack payment amount is invalid.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Compare In Kobo
            |--------------------------------------------------------------------------
            */

            $expectedAmountKobo =
                (int)round(
                    $escrowAmount * 100
                );


            if (
                $expectedAmountKobo
                !==
                $paystackAmountKobo
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PROCESS_AMOUNT_MISMATCH',

                        'escrow_id' =>
                            $escrowId,

                        'reference' =>
                            $reference,

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
                        'Payment amount does not match escrow amount.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Currency Validation
            |--------------------------------------------------------------------------
            */

            $currency =
                strtoupper(
                    trim(
                        (string)(
                            $transaction['currency']
                            ?? ''
                        )
                    )
                );


            if (
                $currency !== ''
                &&
                $currency !== 'NGN'
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PROCESS_CURRENCY_MISMATCH',

                        'escrow_id' =>
                            $escrowId,

                        'reference' =>
                            $reference,

                        'currency' =>
                            $currency
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Payment currency is not supported.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Duplicate / Already Paid
            |--------------------------------------------------------------------------
            */

            $status =
                strtolower(
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

                Logger::write(
                    'paystack_escrow_payment',
                    [
                        'step' =>
                            'PROCESS_ALREADY_PROCESSED',

                        'escrow_id' =>
                            $escrowId,

                        'reference' =>
                            $escrowReference,

                        'status' =>
                            $status
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

                    'escrow_id' =>
                        $escrowId,

                    'status' =>
                        $status
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Mark Escrow Paid
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'PROCESS_MARK_PAID_START',

                    'escrow_id' =>
                        $escrowId,

                    'reference' =>
                        $reference
                ]
            );


            $paid =
                $this->escrowModel->markPaid(
                    $escrowId,
                    $reference
                );


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'PROCESS_MARK_PAID_RESULT',

                    'escrow_id' =>
                        $escrowId,

                    'reference' =>
                        $reference,

                    'result' =>
                        $paid
                ]
            );


            if (
                $paid === false
            ) {

                /*
                 * Reload once.
                 *
                 * Another webhook may have completed the
                 * atomic transition before this request.
                 */

                $after =
                    $this->escrowModel->find(
                        $escrowId
                    );


                if (
                    is_array($after)
                ) {

                    $afterStatus =
                        strtolower(
                            trim(
                                (string)(
                                    $after['status']
                                    ?? ''
                                )
                            )
                        );

                    $afterReference =
                        strtoupper(
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
                        $afterReference === $reference
                    ) {

                        Logger::write(
                            'paystack_escrow_payment',
                            [
                                'step' =>
                                    'PROCESS_CONCURRENT_PAYMENT_CONFIRMED',

                                'escrow_id' =>
                                    $escrowId,

                                'reference' =>
                                    $reference,

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

                            'escrow_id' =>
                                $escrowId,

                            'status' =>
                                $afterStatus
                        ];
                    }
                }


                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PROCESS_MARK_PAID_FAILED',

                        'escrow_id' =>
                            $escrowId,

                        'reference' =>
                            $reference
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
            | Reload Escrow
            |--------------------------------------------------------------------------
            */

            $escrow =
                $this->escrowModel->find(
                    $escrowId
                )
                ?: $escrow;


            /*
            |--------------------------------------------------------------------------
            | Buyer Message
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


            /*
            |--------------------------------------------------------------------------
            | Seller Message
            |--------------------------------------------------------------------------
            */

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
            */

            $this->queueNotifications(
                $escrow,
                $reference,
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

                    'reference' =>
                        $escrowReference,

                    'status' =>
                        $escrow['status']
                        ?? 'paid'
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

                'escrow_id' =>
                    $escrowId,

                'status' =>
                    $escrow['status']
                    ?? 'paid'
            ];

        } catch (Throwable $e) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'PROCESS_EXCEPTION',

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
                'retry' => true,
                'message' =>
                    'Escrow payment processing failed.'
            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * Backward Compatibility
     * ---------------------------------------------------------
     *
     * Keep older callers working.
     * ---------------------------------------------------------
     */
    public function handleSuccessfulPayment(
        array $transaction
    ): array {

        Logger::write(
            'paystack_escrow_payment',
            [
                'step' =>
                    'LEGACY_HANDLE_SUCCESSFUL_PAYMENT',

                'reference' =>
                    $transaction['reference']
                    ?? null
            ]
        );


        return $this->process(
            $transaction
        );
    }


    /**
     * ---------------------------------------------------------
     * Find Escrow By Reference
     * ---------------------------------------------------------
     */
    protected function findEscrowByReference(
        string $reference
    ): ?array {

        $reference =
            strtoupper(
                trim($reference)
            );


        try {

            /*
            |--------------------------------------------------------------------------
            | Preferred Method
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

                    Logger::write(
                        'paystack_escrow_payment',
                        [
                            'step' =>
                                'ESCROW_FOUND_BY_REFERENCE',

                            'reference' =>
                                $reference
                        ]
                    );

                    return $result;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Legacy Method
            |--------------------------------------------------------------------------
            */

            if (
                method_exists(
                    $this->escrowModel,
                    'findByEscrowReference'
                )
            ) {

                $result =
                    $this->escrowModel
                        ->findByEscrowReference(
                            $reference
                        );


                if (
                    is_array($result)
                    &&
                    !empty($result)
                ) {

                    Logger::write(
                        'paystack_escrow_payment',
                        [
                            'step' =>
                                'ESCROW_FOUND_BY_LEGACY_REFERENCE',

                            'reference' =>
                                $reference
                        ]
                    );

                    return $result;
                }
            }


            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'ESCROW_REFERENCE_LOOKUP_FAILED',

                    'reference' =>
                        $reference
                ]
            );

            return null;

        } catch (Throwable $e) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'ESCROW_REFERENCE_LOOKUP_EXCEPTION',

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
     * Extract Escrow Amount
     * ---------------------------------------------------------
     *
     * total_amount is authoritative because this is the amount
     * actually charged to the buyer.
     *
     * Fallback to amount for older escrow records.
     * ---------------------------------------------------------
     */
    protected function extractEscrowAmount(
        array $escrow
    ): float {

        /*
        |--------------------------------------------------------------------------
        | Preferred: total_amount
        |--------------------------------------------------------------------------
        */

        if (
            isset($escrow['total_amount'])
            &&
            is_numeric(
                $escrow['total_amount']
            )
            &&
            (float)$escrow['total_amount'] > 0
        ) {

            return
                (float)$escrow['total_amount'];
        }


        /*
        |--------------------------------------------------------------------------
        | Fallback: amount
        |--------------------------------------------------------------------------
        */

        if (
            isset($escrow['amount'])
            &&
            is_numeric(
                $escrow['amount']
            )
            &&
            (float)$escrow['amount'] > 0
        ) {

            return
                (float)$escrow['amount'];
        }


        return 0.0;
    }


    /**
     * ---------------------------------------------------------
     * Default Callback URL
     * ---------------------------------------------------------
     */
    protected function defaultCallbackUrl(): string
    {
        /*
        |--------------------------------------------------------------------------
        | Dedicated Escrow Callback
        |--------------------------------------------------------------------------
        */

        if (
            defined(
                'PAYSTACK_ESCROW_CALLBACK_URL'
            )
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
        | Application URL
        |--------------------------------------------------------------------------
        */

        if (
            defined('APP_URL')
            &&
            trim(
                (string)APP_URL
            ) !== ''
        ) {

            return
                rtrim(
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
     * Queue Notifications
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
                                $paymentReference
                        ]
                    );
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Seller
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | Use a different notification key from the buyer.
            |
            | This avoids one user's notification preventing the
            | other user's notification from being created.
            |
            */

            $sellerId =
                (int)(
                    $escrow['seller_id']
                    ?? 0
                );


            if (
                $sellerId > 0
            ) {

                $sellerNotificationReference =
                    $paymentReference
                    .
                    ':seller';


                $exists =
                    $notification->exists(
                        $sellerId,
                        'escrow_paid',
                        $sellerNotificationReference
                    );


                if (
                    !$exists
                ) {

                    $notification->create(
                        $sellerId,
                        'escrow_paid',
                        'Buyer Payment Received',
                        $sellerMessage,
                        $sellerNotificationReference
                    );


                    Logger::write(
                        'paystack_escrow_payment',
                        [
                            'step' =>
                                'SELLER_NOTIFICATION_CREATED',

                            'seller_id' =>
                                $sellerId,

                            'payment_reference' =>
                                $paymentReference
                        ]
                    );
                }
            }

        } catch (Throwable $e) {

            /*
             * Notification failure MUST NOT undo the successful
             * financial escrow payment.
             */

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

                    'trace' =>
                        $e->getTraceAsString()
                ]
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * Send Immediate Messages
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


                if (
                    is_array($buyer)
                ) {

                    $platform =
                        strtolower(
                            trim(
                                (string)(
                                    $buyer['platform']
                                    ?? ''
                                )
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

                        $reply =
                            ReplyFactory::make(
                                $platform
                            );


                        $reply->text(
                            $platformId,
                            $buyerMessage
                        );


                        Logger::write(
                            'paystack_escrow_payment',
                            [
                                'step' =>
                                    'BUYER_MESSAGE_SENT',

                                'buyer_id' =>
                                    $buyerId,

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


                if (
                    is_array($seller)
                ) {

                    $platform =
                        strtolower(
                            trim(
                                (string)(
                                    $seller['platform']
                                    ?? ''
                                )
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

                        $reply =
                            ReplyFactory::make(
                                $platform
                            );


                        $reply->text(
                            $platformId,
                            $sellerMessage
                        );


                        Logger::write(
                            'paystack_escrow_payment',
                            [
                                'step' =>
                                    'SELLER_MESSAGE_SENT',

                                'seller_id' =>
                                    $sellerId,

                                'platform' =>
                                    $platform
                            ]
                        );
                    }
                }
            }

        } catch (Throwable $e) {

            /*
             * Immediate messaging failure must NOT undo the
             * successful financial state.
             */

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
                        $e->getLine(),

                    'trace' =>
                        $e->getTraceAsString()
                ]
            );
        }
    }
}