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

    protected EscrowService $escrowService;


    /**
     * Escrow states which mean payment has already
     * successfully moved beyond the pending stage.
     */
    protected array $paidStatuses = [
        'paid',
        'item_sent',
        'awaiting_payout',
        'buyer_confirmed',
        'completed',
    ];


    public function __construct()
    {
        $this->escrowModel =
            new Escrow();

        $this->gateway =
            new PaystackGateway();

        /*
         * EscrowService owns fee configuration/calculation.
         *
         * This service must not duplicate those rules.
         */
        $this->escrowService =
            new EscrowService();


        Logger::write(
            'paystack_escrow_payment',
            [
                'step' => 'CONSTRUCTOR',
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * INITIALIZE ESCROW PAYMENT
     * ---------------------------------------------------------
     *
     * Creates the Paystack payment session.
     *
     * IMPORTANT:
     *
     * - The escrow record is authoritative for amount.
     * - The public escrow reference is NOT automatically used
     *   as the Paystack transaction reference.
     * - The generated Paystack reference is stored when possible.
     * - Fees are calculated by EscrowService.
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

                    'escrow_reference' =>
                        $reference,

                    'email' =>
                        $email,

                    'callback_url' =>
                        $callbackUrl,
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
                            'INITIALIZE_REFERENCE_MISSING',
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Escrow reference is required.',
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
            | Load Escrow
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
                            $reference,
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Escrow transaction not found.',
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
                            $escrow,
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Invalid escrow transaction.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Status
            |--------------------------------------------------------------------------
            */

            $status =
                $this->normalizeStatus(
                    $escrow['status']
                    ?? ''
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
                        $status,
                ]
            );


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
                            'INITIALIZE_ALREADY_PAID',

                        'escrow_id' =>
                            $escrowId,

                        'reference' =>
                            $reference,

                        'status' =>
                            $status,
                    ]
                );

                return [
                    'success' => false,
                    'already_processed' => true,
                    'message' =>
                        'This escrow payment has already been processed.',
                    'reference' =>
                        $reference,
                    'status' =>
                        $status,
                ];
            }


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
                            $status,
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'This escrow cannot currently accept payment.',
                    'reference' =>
                        $reference,
                    'status' =>
                        $status,
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Resolve Escrow Amount
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
                            'INITIALIZE_INVALID_AMOUNT',

                        'escrow_id' =>
                            $escrowId,

                        'reference' =>
                            $reference,

                        'amount' =>
                            $escrowAmount,
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Escrow payment amount is invalid.',
                ];
            }


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
                            ??
                            'NGN'
                        )
                    )
                );


            if (
                $currency === ''
            ) {
                $currency = 'NGN';
            }


            if (
                $currency !== 'NGN'
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_UNSUPPORTED_CURRENCY',

                        'escrow_id' =>
                            $escrowId,

                        'currency' =>
                            $currency,
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Only NGN escrow payments are currently supported.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Calculate Buyer Total
            |--------------------------------------------------------------------------
            |
            | EscrowService owns the fee rules.
            |
            */

            $fees =
                $this->escrowService
                    ->calculateBuyerTotal(
                        $escrowAmount
                    );


            $buyerTotal =
                (float)(
                    $fees['buyer_total']
                    ?? 0
                );


            if (
                $buyerTotal <= 0
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_BUYER_TOTAL_INVALID',

                        'escrow_id' =>
                            $escrowId,

                        'reference' =>
                            $reference,

                        'fees' =>
                            $fees,
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Unable to calculate escrow payment total.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Payment Reference
            |--------------------------------------------------------------------------
            |
            | Reuse an existing reference when available.
            |
            | Otherwise generate a new Paystack reference.
            |
            */

            $paymentReference =
                trim(
                    (string)(
                        $escrow['payment_reference']
                        ?? ''
                    )
                );


            if (
                $paymentReference === ''
            ) {

                $paymentReference =
                    $this->generatePaymentReference(
                        $reference
                    );
            }


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'INITIALIZE_REFERENCE_READY',

                    'escrow_id' =>
                        $escrowId,

                    'escrow_reference' =>
                        $reference,

                    'payment_reference' =>
                        $paymentReference,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Callback
            |--------------------------------------------------------------------------
            */

            $callbackUrl =
                trim(
                    (string)(
                        $callbackUrl
                        ??
                        ''
                    )
                );


            if (
                $callbackUrl === ''
            ) {

                $callbackUrl =
                    $this->defaultCallbackUrl();
            }


            if (
                $callbackUrl === ''
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_CALLBACK_MISSING',

                        'reference' =>
                            $reference,
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Paystack callback URL is not configured.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Metadata
            |--------------------------------------------------------------------------
            |
            | Keep both identifiers.
            |
            | escrow_reference
            |     = public escrow identifier
            |
            | reference
            |     = actual Paystack transaction reference
            |
            */

            $metadata = [

                'type' =>
                    'escrow_payment',

                'payment_type' =>
                    'escrow',

                'source' =>
                    'sendam_escrow',

                'escrow_id' =>
                    $escrowId,

                'escrow_reference' =>
                    $reference,

                'reference' =>
                    $reference,

                'payment_reference' =>
                    $paymentReference,

                'listing_id' =>
                    $escrow['listing_id']
                    ?? null,

                'buyer_id' =>
                    $escrow['buyer_id']
                    ?? null,

                'seller_id' =>
                    $escrow['seller_id']
                    ?? null,

                'escrow_amount' =>
                    $fees['amount']
                    ?? $escrowAmount,

                'escrow_fee' =>
                    $fees['escrow_fee']
                    ?? 0,

                'paystack_fee' =>
                    $fees['paystack_fee']
                    ?? 0,

                'buyer_total' =>
                    $buyerTotal,

                'currency' =>
                    $currency,
            ];


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'INITIALIZE_PAYSTACK_START',

                    'escrow_id' =>
                        $escrowId,

                    'escrow_reference' =>
                        $reference,

                    'payment_reference' =>
                        $paymentReference,

                    'amount_ngn' =>
                        $buyerTotal,

                    'amount_kobo' =>
                        $this->toKobo(
                            $buyerTotal
                        ),

                    'currency' =>
                        $currency,

                    'callback_url' =>
                        $callbackUrl,

                    'metadata' =>
                        $metadata,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Initialize Paystack
            |--------------------------------------------------------------------------
            */

            $result =
                $this->gateway->initialize(
                    (int)round(
                        $buyerTotal
                    ),
                    $email,
                    $paymentReference,
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

                    'payment_reference' =>
                        $paymentReference,

                    'success' =>
                        $result['success']
                        ?? false,

                    'paystack_reference' =>
                        $result['reference']
                        ?? null,

                    'authorization_url' =>
                        $result['authorization_url']
                        ?? null,
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
                        'Unable to initialize escrow payment.',

                    'reference' =>
                        $reference,

                    'payment_reference' =>
                        $paymentReference,

                    'raw' =>
                        $result['raw']
                        ?? null,
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Use Actual Paystack Reference
            |--------------------------------------------------------------------------
            */

            $actualPaymentReference =
                trim(
                    (string)(
                        $result['reference']
                        ??
                        $paymentReference
                    )
                );


            if (
                $actualPaymentReference === ''
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_PAYSTACK_REFERENCE_MISSING',

                        'escrow_id' =>
                            $escrowId,

                        'reference' =>
                            $reference,
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Paystack did not return a transaction reference.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Persist Payment Reference
            |--------------------------------------------------------------------------
            |
            | This is useful for:
            |
            | - duplicate initialization
            | - webhook reference matching
            | - audit trail
            |
            | Failure to persist here does NOT invalidate the Paystack
            | payment itself because markPaid() remains the authoritative
            | payment-state transition.
            |--------------------------------------------------------------------------
            */

            $this->persistPaymentReference(
                $escrowId,
                $actualPaymentReference
            );


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

                        'escrow_id' =>
                            $escrowId,

                        'reference' =>
                            $reference,

                        'payment_reference' =>
                            $actualPaymentReference,
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Paystack did not return a payment link.',
                    'reference' =>
                        $reference,
                    'payment_reference' =>
                        $actualPaymentReference,
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

                    'escrow_reference' =>
                        $reference,

                    'payment_reference' =>
                        $actualPaymentReference,

                    'amount' =>
                        $buyerTotal,
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
                    $actualPaymentReference,

                'paystack_reference' =>
                    $actualPaymentReference,

                'amount' =>
                    $fees['amount']
                    ?? $escrowAmount,

                'escrow_fee' =>
                    $fees['escrow_fee']
                    ?? 0,

                'paystack_fee' =>
                    $fees['paystack_fee']
                    ?? 0,

                'total' =>
                    $buyerTotal,

                'currency' =>
                    $currency,

                'authorization_url' =>
                    $authorizationUrl,

                'access_code' =>
                    $result['access_code']
                    ?? null,

                'escrow' =>
                    $escrow,

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
                'success' => false,
                'message' =>
                    'Unable to initialize escrow payment.',
            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * PROCESS VERIFIED PAYMENT
     * ---------------------------------------------------------
     *
     * This method receives an ALREADY VERIFIED Paystack
     * transaction.
     *
     * It does NOT call Paystack again.
     *
     * The webhook listener / payment listener is responsible
     * for verification.
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
            | Validate Transaction
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
                            'PROCESS_PAYMENT_NOT_SUCCESSFUL',

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
            | Accept both metadata values during migration:
            |
            | escrow
            | escrow_payment
            |
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


            $secondaryPaymentType =
                strtolower(
                    trim(
                        (string)(
                            $metadata['payment_type']
                            ?? ''
                        )
                    )
                );


            $validEscrowPayment =
                $paymentType === 'escrow'
                ||
                $paymentType === 'escrow_payment'
                ||
                $secondaryPaymentType === 'escrow';


            if (
                !$validEscrowPayment
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PROCESS_INVALID_PAYMENT_TYPE',

                        'payment_reference' =>
                            $paymentReference,

                        'type' =>
                            $paymentType,

                        'payment_type' =>
                            $secondaryPaymentType,
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
                            ''
                        )
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | Locate Escrow
            |--------------------------------------------------------------------------
            |
            | Prefer ID because it is the strongest identifier.
            |
            | If metadata has no ID, use the escrow reference.
            |--------------------------------------------------------------------------
            */

            $escrow = null;


            if (
                $escrowId > 0
            ) {

                Logger::write(
                    'paystack_escrow_payment',
                    [
                        'step' =>
                            'PROCESS_ESCROW_LOOKUP_BY_ID',

                        'escrow_id' =>
                            $escrowId,

                        'payment_reference' =>
                            $paymentReference,
                    ]
                );


                $escrow =
                    $this->escrowModel->find(
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
                                'PROCESS_ESCROW_LOOKUP_BY_REFERENCE',

                            'reference' =>
                                $metadataEscrowReference,

                            'payment_reference' =>
                                $paymentReference,
                        ]
                    );


                    $escrow =
                        $this->findEscrowByReference(
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
                            'PROCESS_ESCROW_NOT_FOUND',

                        'escrow_id' =>
                            $escrowId,

                        'metadata_reference' =>
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


            $escrowId =
                (int)(
                    $escrow['id']
                    ?? $escrowId
                );


            if (
                $escrowId <= 0
            ) {

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Escrow transaction ID is invalid.',
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
                            'PROCESS_ESCROW_REFERENCE_MISSING',

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
            | Metadata Reference Integrity
            |--------------------------------------------------------------------------
            */

            if (
                $metadataEscrowReference !== ''
                &&
                $metadataEscrowReference !== $escrowReference
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'ESCROW_REFERENCE_MISMATCH',

                        'payment_reference' =>
                            $paymentReference,

                        'metadata_reference' =>
                            $metadataEscrowReference,

                        'database_reference' =>
                            $escrowReference,

                        'escrow_id' =>
                            $escrowId,
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
            | Payment Reference Integrity
            |--------------------------------------------------------------------------
            */

            $existingPaymentReference =
                strtoupper(
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
                $existingPaymentReference !==
                    $paymentReference
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PAYMENT_REFERENCE_CONFLICT',

                        'escrow_id' =>
                            $escrowId,

                        'escrow_reference' =>
                            $escrowReference,

                        'existing_payment_reference' =>
                            $existingPaymentReference,

                        'incoming_payment_reference' =>
                            $paymentReference,
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'This escrow is already linked to another payment.',
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


            if (
                $escrowAmount <= 0
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INVALID_ESCROW_AMOUNT',

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

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Unable to calculate expected payment amount.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Paystack Amount
            |--------------------------------------------------------------------------
            */

            $paystackAmountKobo =
                (int)(
                    $transaction['amount']
                    ?? 0
                );


            if (
                $paystackAmountKobo <= 0
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

                        'amount' =>
                            $paystackAmountKobo,
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

                    'expected_total' =>
                        $expectedBuyerTotal,

                    'expected_kobo' =>
                        $expectedAmountKobo,

                    'received_kobo' =>
                        $paystackAmountKobo,
                ]
            );


            if (
                $expectedAmountKobo !==
                $paystackAmountKobo
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
                            $paystackAmountKobo,
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
            | Currency Validation
            |--------------------------------------------------------------------------
            */

            $paymentCurrency =
                strtoupper(
                    trim(
                        (string)(
                            $transaction['currency']
                            ?? 'NGN'
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
                $paymentCurrency !==
                $escrowCurrency
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'CURRENCY_MISMATCH',

                        'escrow_id' =>
                            $escrowId,

                        'payment_reference' =>
                            $paymentReference,

                        'payment_currency' =>
                            $paymentCurrency,

                        'escrow_currency' =>
                            $escrowCurrency,
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Payment currency does not match escrow currency.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Current Escrow Status
            |--------------------------------------------------------------------------
            */

            $status =
                $this->normalizeStatus(
                    $escrow['status']
                    ?? ''
                );


            /*
            |--------------------------------------------------------------------------
            | Already Processed
            |--------------------------------------------------------------------------
            */

            if (
                in_array(
                    $status,
                    $this->paidStatuses,
                    true
                )
            ) {

                if (
                    $existingPaymentReference !== ''
                    &&
                    $existingPaymentReference ===
                        $paymentReference
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
                            $status,
                    ];
                }


                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'ADVANCED_STATUS_REFERENCE_CONFLICT',

                        'escrow_id' =>
                            $escrowId,

                        'status' =>
                            $status,

                        'existing_payment_reference' =>
                            $existingPaymentReference,

                        'incoming_payment_reference' =>
                            $paymentReference,
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Escrow is already linked to another payment.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Only Pending Can Become Paid
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
                        'Escrow cannot be marked as paid from its current status.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Mark Paid
            |--------------------------------------------------------------------------
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


            $paid =
                $this->escrowModel->markPaid(
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
                        $paid,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Race Recovery
            |--------------------------------------------------------------------------
            */

            if (
                !$paid
            ) {

                $after =
                    $this->escrowModel->find(
                        $escrowId
                    );


                if (
                    is_array($after)
                    &&
                    !empty($after)
                ) {

                    $afterStatus =
                        $this->normalizeStatus(
                            $after['status']
                            ?? ''
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
                            $this->paidStatuses,
                            true
                        )
                        &&
                        $afterReference ===
                            $paymentReference
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
                                    $afterStatus,
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
                                $afterStatus,
                        ];
                    }
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
                    'success' => false,
                    'retry' => true,
                    'message' =>
                        'Unable to mark escrow as paid.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Reload Final Escrow
            |--------------------------------------------------------------------------
            */

            $escrow =
                $this->escrowModel->find(
                    $escrowId
                )
                ?: $escrow;


            $finalStatus =
                $this->normalizeStatus(
                    $escrow['status']
                    ?? ''
                );


            $finalReference =
                strtoupper(
                    trim(
                        (string)(
                            $escrow['payment_reference']
                            ?? ''
                        )
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | Confirm Final State
            |--------------------------------------------------------------------------
            */

            if (
                $finalReference !==
                $paymentReference
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'FINAL_REFERENCE_MISMATCH',

                        'escrow_id' =>
                            $escrowId,

                        'expected' =>
                            $paymentReference,

                        'actual' =>
                            $finalReference,
                    ]
                );

                return [
                    'success' => false,
                    'retry' => true,
                    'message' =>
                        'Escrow payment reference could not be confirmed.',
                ];
            }


            if (
                !in_array(
                    $finalStatus,
                    $this->paidStatuses,
                    true
                )
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'FINAL_STATUS_INVALID',

                        'escrow_id' =>
                            $escrowId,

                        'status' =>
                            $finalStatus,
                    ]
                );

                return [
                    'success' => false,
                    'retry' => true,
                    'message' =>
                        'Escrow payment state could not be confirmed.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Notification Messages
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
                "Your payment is now secured in escrow.\n"
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
            | Queue Notifications
            |--------------------------------------------------------------------------
            |
            | Notification failure must NEVER change a successful payment
            | into a failed payment.
            |
            */

            try {

                $this->queueNotifications(
                    $escrow,
                    $paymentReference,
                    $buyerMessage,
                    $sellerMessage
                );

            } catch (Throwable $e) {

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
                            $e->getMessage(),
                    ]
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Immediate Messages
            |--------------------------------------------------------------------------
            */

            try {

                $this->sendImmediateMessages(
                    $escrow,
                    $buyerMessage,
                    $sellerMessage
                );

            } catch (Throwable $e) {

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
                            $e->getMessage(),
                    ]
                );
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
                'success' => false,
                'retry' => true,
                'message' =>
                    'Escrow payment processing failed.',
            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * BACKWARD COMPATIBILITY
     * ---------------------------------------------------------
     *
     * Older callers may still call handleSuccessfulPayment().
     *
     * It now delegates directly to process().
     *
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
     * FIND ESCROW BY REFERENCE
     * ---------------------------------------------------------
     */
    protected function findEscrowByReference(
        string $reference
    ): ?array {

        $reference =
            strtoupper(
                trim($reference)
            );


        if (
            $reference === ''
        ) {
            return null;
        }


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
                    $this->escrowModel
                        ->findByReference(
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
                    $this->escrowModel
                        ->findByEscrowReference(
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
                        'ESCROW_REFERENCE_NOT_FOUND',

                    'reference' =>
                        $reference,
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
                        $e->getLine(),
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
     * Returns the base escrow amount in NGN.
     *
     * Fee calculation is deliberately NOT performed here.
     *
     * ---------------------------------------------------------
     */
    protected function extractEscrowAmount(
        array $escrow
    ): float {

        $fields = [
            'amount',
            'total_amount',
            'escrow_amount',
            'payment_amount',
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
     * GENERATE PAYSTACK PAYMENT REFERENCE
     * ---------------------------------------------------------
     */
    protected function generatePaymentReference(
        string $escrowReference
    ): string {

        $escrowReference =
            strtoupper(
                trim($escrowReference)
            );


        try {

            return
                'ESC-'
                .
                $escrowReference
                .
                '-'
                .
                strtoupper(
                    bin2hex(
                        random_bytes(4)
                    )
                );

        } catch (Throwable $e) {

            /*
             * random_bytes() should normally never fail,
             * but preserve a deterministic fallback.
             */

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'PAYMENT_REFERENCE_RANDOM_FAILED',

                    'reference' =>
                        $escrowReference,

                    'message' =>
                        $e->getMessage(),
                ]
            );


            return
                'ESC-'
                .
                $escrowReference
                .
                '-'
                .
                strtoupper(
                    substr(
                        hash(
                            'sha256',
                            uniqid(
                                '',
                                true
                            )
                        ),
                        0,
                        8
                    )
                );
        }
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
     * CONVERT NGN TO KOBO
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
     * PERSIST PAYMENT REFERENCE
     * ---------------------------------------------------------
     *
     * Best-effort persistence.
     *
     * The actual payment state remains controlled by markPaid().
     *
     * ---------------------------------------------------------
     */
    protected function persistPaymentReference(
        int $escrowId,
        string $paymentReference
    ): bool {

        if (
            $escrowId <= 0
            ||
            trim($paymentReference) === ''
        ) {
            return false;
        }


        try {

            /*
            |--------------------------------------------------------------------------
            | Check Current Record
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
                            'PERSIST_REFERENCE_ESCROW_NOT_FOUND',

                        'escrow_id' =>
                            $escrowId,
                    ]
                );

                return false;
            }


            $existing =
                trim(
                    (string)(
                        $escrow['payment_reference']
                        ?? ''
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | Already Stored
            |--------------------------------------------------------------------------
            */

            if (
                $existing !== ''
            ) {

                if (
                    strtoupper($existing)
                    ===
                    strtoupper($paymentReference)
                ) {

                    return true;
                }


                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PERSIST_REFERENCE_CONFLICT',

                        'escrow_id' =>
                            $escrowId,

                        'existing' =>
                            $existing,

                        'incoming' =>
                            $paymentReference,
                    ]
                );


                return false;
            }


            /*
            |--------------------------------------------------------------------------
            | Update Model
            |--------------------------------------------------------------------------
            */

            if (
                !method_exists(
                    $this->escrowModel,
                    'update'
                )
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PERSIST_REFERENCE_UPDATE_UNAVAILABLE',

                        'escrow_id' =>
                            $escrowId,

                        'payment_reference' =>
                            $paymentReference,
                    ]
                );

                return false;
            }


            $result =
                $this->escrowModel->update(
                    $escrowId,
                    [
                        'payment_reference' =>
                            $paymentReference,
                    ]
                );


            $success =
                $result === true
                ||
                $result === 1
                ||
                (
                    is_int($result)
                    &&
                    $result > 0
                );


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'PERSIST_REFERENCE_RESULT',

                    'escrow_id' =>
                        $escrowId,

                    'payment_reference' =>
                        $paymentReference,

                    'success' =>
                        $success,
                ]
            );


            return $success;

        } catch (Throwable $e) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'PERSIST_REFERENCE_EXCEPTION',

                    'escrow_id' =>
                        $escrowId,

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


            return false;
        }
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
        | Dedicated Escrow Callback
        |--------------------------------------------------------------------------
        */

        if (
            defined(
                'PAYSTACK_ESCROW_CALLBACK_URL'
            )
        ) {

            $url =
                trim(
                    (string)
                    PAYSTACK_ESCROW_CALLBACK_URL
                );


            if (
                $url !== ''
            ) {
                return $url;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Application URL
        |--------------------------------------------------------------------------
        */

        if (
            defined('APP_URL')
        ) {

            $appUrl =
                trim(
                    (string)APP_URL
                );


            if (
                $appUrl !== ''
            ) {

                return
                    rtrim(
                        $appUrl,
                        '/'
                    )
                    .
                    '/payment/paystack/escrow/callback';
            }
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
                        $logStep . '_USER_NOT_FOUND',
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
                        $logStep . '_PLATFORM_DATA_MISSING',

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
                        $logStep . '_SENT',

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
                        $logStep . '_FAILED',

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