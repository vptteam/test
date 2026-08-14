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
     * States which mean the payment has already
     * successfully passed the pending state.
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
        $this->escrowModel = new Escrow();

        $this->gateway = new PaystackGateway();

        /*
         * EscrowService remains the single owner of:
         *
         * - escrow fee
         * - Paystack fee
         * - buyer total
         * - escrow business rules
         */
        $this->escrowService = new EscrowService();

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
     * This method does NOT mark the escrow as paid.
     *
     * Payment state changes only after Paystack verification.
     */
    public function initialize(
        string $reference,
        string $email,
        ?string $callbackUrl = null
    ): array {

        $reference = strtoupper(trim($reference));
        $email = trim($email);

        try {

            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' => 'INITIALIZE_START',
                    'escrow_reference' => $reference,
                    'email' => $email,
                    'callback_url' => $callbackUrl,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Validate reference
            |--------------------------------------------------------------------------
            */

            if ($reference === '') {

                return $this->failure(
                    'Escrow reference is required.',
                    'INITIALIZE_REFERENCE_MISSING'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Validate email
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
                        'step' => 'INITIALIZE_EMAIL_INVALID',
                        'reference' => $reference,
                        'email' => $email,
                    ]
                );

                return [
                    'success' => false,
                    'message' => 'A valid email address is required.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Load escrow
            |--------------------------------------------------------------------------
            */

            $escrow = $this->escrowModel->findByReference(
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
                        'step' => 'INITIALIZE_ESCROW_NOT_FOUND',
                        'reference' => $reference,
                    ]
                );

                return [
                    'success' => false,
                    'message' => 'Escrow transaction not found.',
                ];
            }


            $escrowId = (int)($escrow['id'] ?? 0);

            if ($escrowId <= 0) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' => 'INITIALIZE_INVALID_ESCROW_ID',
                        'reference' => $reference,
                        'escrow' => $escrow,
                    ]
                );

                return [
                    'success' => false,
                    'message' => 'Invalid escrow transaction.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            $status = $this->normalizeStatus(
                $escrow['status'] ?? ''
            );


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' => 'INITIALIZE_ESCROW_STATUS',
                    'escrow_id' => $escrowId,
                    'reference' => $reference,
                    'status' => $status,
                ]
            );


            if (
                in_array(
                    $status,
                    $this->paidStatuses,
                    true
                )
            ) {

                return [
                    'success' => false,
                    'already_processed' => true,
                    'message' =>
                        'This escrow payment has already been processed.',
                    'reference' => $reference,
                    'status' => $status,
                ];
            }


            if ($status !== 'pending') {

                return [
                    'success' => false,
                    'message' =>
                        'This escrow cannot currently accept payment.',
                    'reference' => $reference,
                    'status' => $status,
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Currency
            |--------------------------------------------------------------------------
            */

            $currency = strtoupper(
                trim(
                    (string)(
                        $escrow['currency']
                        ?? 'NGN'
                    )
                )
            );

            if ($currency === '') {
                $currency = 'NGN';
            }


            if ($currency !== 'NGN') {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' => 'INITIALIZE_UNSUPPORTED_CURRENCY',
                        'escrow_id' => $escrowId,
                        'currency' => $currency,
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
            | Base escrow amount
            |--------------------------------------------------------------------------
            */

            $escrowAmount = $this->extractEscrowAmount(
                $escrow
            );


            if ($escrowAmount <= 0) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' => 'INITIALIZE_INVALID_AMOUNT',
                        'escrow_id' => $escrowId,
                        'reference' => $reference,
                        'amount' => $escrowAmount,
                    ]
                );

                return [
                    'success' => false,
                    'message' => 'Escrow payment amount is invalid.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Calculate fees
            |--------------------------------------------------------------------------
            |
            | EscrowService is the ONLY place that calculates
            | the escrow/payment fees.
            |
            */

            $fees = $this->escrowService->calculateBuyerTotal(
                $escrowAmount
            );


            $buyerTotal = (float)(
                $fees['buyer_total']
                ?? 0
            );


            if ($buyerTotal <= 0) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' => 'INITIALIZE_BUYER_TOTAL_INVALID',
                        'escrow_id' => $escrowId,
                        'reference' => $reference,
                        'fees' => $fees,
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
            | Paystack reference
            |--------------------------------------------------------------------------
            */

            $paymentReference = trim(
                (string)(
                    $escrow['payment_reference']
                    ?? ''
                )
            );


            if ($paymentReference === '') {

                $paymentReference =
                    $this->generatePaymentReference(
                        $reference
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | Callback
            |--------------------------------------------------------------------------
            */

            $callbackUrl = trim(
                (string)(
                    $callbackUrl
                    ?? ''
                )
            );


            if ($callbackUrl === '') {

                $callbackUrl = $this->defaultCallbackUrl();
            }


            if ($callbackUrl === '') {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' => 'INITIALIZE_CALLBACK_MISSING',
                        'reference' => $reference,
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
            | IMPORTANT:
            |
            | type = escrow
            |
            | This is the canonical value expected by the
            | escrow payment processor.
            |
            */

            $metadata = [
                'type' => 'escrow',

                'payment_type' => 'escrow',

                'source' => 'sendam_escrow',

                'escrow_id' => $escrowId,

                'escrow_reference' => $reference,

                'payment_reference' => $paymentReference,

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
                    'step' => 'INITIALIZE_PAYSTACK',
                    'escrow_id' => $escrowId,
                    'escrow_reference' => $reference,
                    'payment_reference' => $paymentReference,
                    'amount_ngn' => $buyerTotal,
                    'amount_kobo' => $this->toKobo($buyerTotal),
                    'callback_url' => $callbackUrl,
                    'metadata' => $metadata,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Paystack Gateway
            |--------------------------------------------------------------------------
            |
            | Gateway owns the actual HTTP request to Paystack.
            |
            */

            $result = $this->gateway->initialize(
                (int)round($buyerTotal),
                $email,
                $paymentReference,
                $callbackUrl,
                $metadata
            );


            if (
                !is_array($result)
                ||
                !($result['success'] ?? false)
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' => 'INITIALIZE_PAYSTACK_FAILED',
                        'escrow_id' => $escrowId,
                        'reference' => $reference,
                        'payment_reference' => $paymentReference,
                        'result' => $result,
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        $result['message']
                        ??
                        'Unable to initialize escrow payment.',
                    'reference' => $reference,
                    'payment_reference' => $paymentReference,
                    'raw' => $result['raw'] ?? null,
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Actual Paystack reference
            |--------------------------------------------------------------------------
            */

            $actualPaymentReference = strtoupper(
                trim(
                    (string)(
                        $result['reference']
                        ?? $paymentReference
                    )
                )
            );


            if ($actualPaymentReference === '') {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_PAYSTACK_REFERENCE_MISSING',
                        'escrow_id' => $escrowId,
                        'reference' => $reference,
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
            | Persist payment reference
            |--------------------------------------------------------------------------
            |
            | This does NOT change payment status.
            |
            */

            $persisted = $this->persistPaymentReference(
                $escrowId,
                $actualPaymentReference
            );


            if (!$persisted) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'INITIALIZE_PAYMENT_REFERENCE_NOT_PERSISTED',
                        'escrow_id' => $escrowId,
                        'payment_reference' =>
                            $actualPaymentReference,
                    ]
                );

                /*
                 * Do not destroy a valid Paystack session.
                 * Return the payment URL but expose the persistence
                 * problem in logs.
                 */
            }


            $authorizationUrl = trim(
                (string)(
                    $result['authorization_url']
                    ?? ''
                )
            );


            if ($authorizationUrl === '') {

                return [
                    'success' => false,
                    'message' =>
                        'Paystack did not return a payment link.',
                    'reference' => $reference,
                    'payment_reference' =>
                        $actualPaymentReference,
                ];
            }


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' => 'INITIALIZE_COMPLETE',
                    'escrow_id' => $escrowId,
                    'escrow_reference' => $reference,
                    'payment_reference' =>
                        $actualPaymentReference,
                    'amount' => $buyerTotal,
                ]
            );


            return [
                'success' => true,

                'message' =>
                    'Escrow payment initialized successfully.',

                'escrow_id' => $escrowId,

                'reference' => $reference,

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
                    'step' => 'INITIALIZE_EXCEPTION',
                    'reference' => $reference,
                    'email' => $email,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
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
     * IMPORTANT:
     *
     * This method accepts an already verified Paystack
     * transaction.
     *
     * It does NOT call PaystackGateway::verify().
     *
     * The webhook/listener performs verification.
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
                    'payment_reference' =>
                        $paymentReference,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Basic transaction validation
            |--------------------------------------------------------------------------
            */

            if (empty($transaction)) {

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Verified payment data is unavailable.',
                ];
            }


            if ($paymentReference === '') {

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Payment reference is missing.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Verify successful status
            |--------------------------------------------------------------------------
            */

            $paymentStatus = $this->normalizeStatus(
                $transaction['status'] ?? ''
            );


            if ($paymentStatus !== 'success') {

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Payment is not successful.',
                    'reference' =>
                        $paymentReference,
                    'status' =>
                        $paymentStatus,
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


            if (!is_array($metadata)) {
                $metadata = [];
            }


            /*
            |--------------------------------------------------------------------------
            | Payment type
            |--------------------------------------------------------------------------
            |
            | Canonical:
            |
            | type = escrow
            |
            | Legacy values remain accepted to avoid breaking
            | already-created transactions.
            |--------------------------------------------------------------------------
            */

            $type = $this->normalizeStatus(
                $metadata['type'] ?? ''
            );

            $paymentType = $this->normalizeStatus(
                $metadata['payment_type'] ?? ''
            );


            if (
                $type !== 'escrow'
                &&
                $type !== 'escrow_payment'
                &&
                $paymentType !== 'escrow'
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PROCESS_INVALID_PAYMENT_TYPE',
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

            $escrowId = (int)(
                $metadata['escrow_id']
                ?? 0
            );


            /*
            |--------------------------------------------------------------------------
            | Escrow lookup
            |--------------------------------------------------------------------------
            */

            $escrow = null;


            if ($escrowId > 0) {

                $escrow =
                    $this->escrowModel->find(
                        $escrowId
                    );
            }


            /*
             * Fallback to public escrow reference.
             */
            if (
                !is_array($escrow)
                ||
                empty($escrow)
            ) {

                $metadataEscrowReference =
                    strtoupper(
                        trim(
                            (string)(
                                $metadata['escrow_reference']
                                ?? ''
                            )
                        )
                    );


                if ($metadataEscrowReference !== '') {

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
                            'PROCESS_ESCROW_NOT_FOUND',
                        'payment_reference' =>
                            $paymentReference,
                        'escrow_id' =>
                            $escrowId,
                        'metadata' =>
                            $metadata,
                    ]
                );

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Escrow transaction not found.',
                ];
            }


            $escrowId = (int)(
                $escrow['id']
                ?? $escrowId
            );


            if ($escrowId <= 0) {

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Invalid escrow transaction.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Escrow public reference
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

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Escrow reference is missing.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Metadata escrow reference integrity
            |--------------------------------------------------------------------------
            */

            $metadataEscrowReference =
                strtoupper(
                    trim(
                        (string)(
                            $metadata['escrow_reference']
                            ?? ''
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
            | Paystack reference integrity
            |--------------------------------------------------------------------------
            |
            | The Paystack transaction reference must match the
            | reference stored on the escrow once one exists.
            |--------------------------------------------------------------------------
            */

            $storedPaymentReference = strtoupper(
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
                $storedPaymentReference !== $paymentReference
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
                        'Payment reference does not match escrow payment reference.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Escrow status
            |--------------------------------------------------------------------------
            */

            $status = $this->normalizeStatus(
                $escrow['status'] ?? ''
            );


            /*
            |--------------------------------------------------------------------------
            | Idempotency
            |--------------------------------------------------------------------------
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
                            'PROCESS_ALREADY_PAID',
                        'escrow_id' =>
                            $escrowId,
                        'reference' =>
                            $escrowReference,
                        'payment_reference' =>
                            $paymentReference,
                        'status' =>
                            $status,
                    ]
                );


                /*
                 * If payment reference is missing on an already
                 * paid record, attach it without changing status.
                 */
                if (
                    $storedPaymentReference === ''
                ) {

                    $this->persistPaymentReference(
                        $escrowId,
                        $paymentReference
                    );
                }


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


            if ($status !== 'pending') {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PROCESS_INVALID_ESCROW_STATUS',
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
            | Amount validation
            |--------------------------------------------------------------------------
            */

            $escrowAmount =
                $this->extractEscrowAmount(
                    $escrow
                );


            if ($escrowAmount <= 0) {

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Escrow amount is invalid.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Calculate expected buyer total
            |--------------------------------------------------------------------------
            */

            $fees =
                $this->escrowService
                    ->calculateBuyerTotal(
                        $escrowAmount
                    );


            $expectedBuyerTotal = (float)(
                $fees['buyer_total']
                ?? 0
            );


            if ($expectedBuyerTotal <= 0) {

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


            $receivedAmountKobo = (int)(
                $transaction['amount']
                ?? 0
            );


            if ($receivedAmountKobo <= 0) {

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
            | Currency validation
            |--------------------------------------------------------------------------
            */

            $paymentCurrency = strtoupper(
                trim(
                    (string)(
                        $transaction['currency']
                        ?? 'NGN'
                    )
                )
            );


            $escrowCurrency = strtoupper(
                trim(
                    (string)(
                        $escrow['currency']
                        ?? 'NGN'
                    )
                )
            );


            if ($paymentCurrency === '') {
                $paymentCurrency = 'NGN';
            }

            if ($escrowCurrency === '') {
                $escrowCurrency = 'NGN';
            }


            if (
                $paymentCurrency !== 'NGN'
                ||
                $escrowCurrency !== 'NGN'
            ) {

                return [
                    'success' => false,
                    'retry' => false,
                    'message' =>
                        'Only matching NGN escrow payments are accepted.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Mark paid
            |--------------------------------------------------------------------------
            |
            | Escrow model owns the state transition.
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'MARK_PAID_START',
                    'escrow_id' =>
                        $escrowId,
                    'payment_reference' =>
                        $paymentReference,
                ]
            );


            $markedPaid =
                $this->escrowModel->markPaid(
                    $escrowId,
                    $paymentReference
                );


            /*
            |--------------------------------------------------------------------------
            | Concurrency / idempotency recovery
            |--------------------------------------------------------------------------
            */

            if (!$markedPaid) {

                $after =
                    $this->escrowModel->find(
                        $escrowId
                    );


                if (
                    is_array($after)
                    &&
                    in_array(
                        $this->normalizeStatus(
                            $after['status'] ?? ''
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
                    ) === $paymentReference
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
                            $this->normalizeStatus(
                                $after['status']
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
                    'success' => false,
                    'retry' => true,
                    'message' =>
                        'Unable to mark escrow as paid.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Reload authoritative escrow
            |--------------------------------------------------------------------------
            */

            $escrow =
                $this->escrowModel->find(
                    $escrowId
                )
                ??
                $escrow;


            $finalStatus =
                $this->normalizeStatus(
                    $escrow['status'] ?? ''
                );


            $finalPaymentReference =
                strtoupper(
                    trim(
                        (string)(
                            $escrow['payment_reference']
                            ?? ''
                        )
                    )
                );


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
                        'final_status' =>
                            $finalStatus,
                        'final_payment_reference' =>
                            $finalPaymentReference,
                        'expected_payment_reference' =>
                            $paymentReference,
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
            | Notifications
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
             * Notification failure must never reverse
             * a successful payment.
             */

            $this->queueNotifications(
                $escrow,
                $paymentReference,
                $buyerMessage,
                $sellerMessage
            );


            $this->sendImmediateMessages(
                $escrow,
                $buyerMessage,
                $sellerMessage
            );


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
     * FIND ESCROW
     * ---------------------------------------------------------
     */
    protected function findEscrowByReference(
        string $reference
    ): ?array {

        $reference = strtoupper(
            trim($reference)
        );


        if ($reference === '') {
            return null;
        }


        return $this->escrowModel->findByReference(
            $reference
        );
    }


    /**
     * ---------------------------------------------------------
     * PERSIST PAYMENT REFERENCE
     * ---------------------------------------------------------
     *
     * Does NOT change escrow status.
     */
    protected function persistPaymentReference(
        int $escrowId,
        string $paymentReference
    ): bool {

        $paymentReference = strtoupper(
            trim($paymentReference)
        );


        if (
            $escrowId <= 0
            ||
            $paymentReference === ''
        ) {
            return false;
        }


        try {

            $escrow =
                $this->escrowModel->find(
                    $escrowId
                );


            if (
                !is_array($escrow)
                ||
                empty($escrow)
            ) {
                return false;
            }


            $existing = strtoupper(
                trim(
                    (string)(
                        $escrow['payment_reference']
                        ?? ''
                    )
                )
            );


            if ($existing !== '') {

                return hash_equals(
                    $existing,
                    $paymentReference
                );
            }


            return $this->escrowModel->update(
                $escrowId,
                [
                    'payment_reference' =>
                        $paymentReference,
                ]
            );


        } catch (Throwable $e) {

            Logger::write(
                'paystack_escrow_payment_error',
                [
                    'step' =>
                        'PERSIST_PAYMENT_REFERENCE_EXCEPTION',
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
     * GENERATE PAYSTACK REFERENCE
     * ---------------------------------------------------------
     */
    protected function generatePaymentReference(
        string $escrowReference
    ): string {

        $escrowReference =
            strtoupper(
                trim($escrowReference)
            );


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
    }


    /**
     * ---------------------------------------------------------
     * DEFAULT CALLBACK URL
     * ---------------------------------------------------------
     */
    protected function defaultCallbackUrl(): string
    {
        /*
         * Keep callback configuration centralized.
         *
         * If PAYSTACK_ESCROW_CALLBACK_URL exists,
         * use it.
         */

        if (
            defined(
                'PAYSTACK_ESCROW_CALLBACK_URL'
            )
        ) {

            return trim(
                (string)PAYSTACK_ESCROW_CALLBACK_URL
            );
        }


        /*
         * Optional application URL configuration.
         */

        if (
            defined('APP_URL')
            &&
            trim((string)APP_URL) !== ''
        ) {

            return
                rtrim(
                    (string)APP_URL,
                    '/'
                )
                .
                '/api/payments/escrow/paystack/callback';
        }


        return '';
    }


    /**
     * ---------------------------------------------------------
     * EXTRACT ESCROW AMOUNT
     * ---------------------------------------------------------
     *
     * Only extracts the base escrow amount.
     *
     * Fee calculation remains in EscrowService.
     */
    protected function extractEscrowAmount(
        array $escrow
    ): float {

        $fields = [
            'amount',
            'escrow_amount',
            'payment_amount',
            'price',
        ];


        foreach ($fields as $field) {

            if (
                !array_key_exists(
                    $field,
                    $escrow
                )
            ) {
                continue;
            }


            $value = $escrow[$field];


            if (!is_numeric($value)) {
                continue;
            }


            $amount = (float)$value;


            if ($amount > 0) {

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
     * NGN -> KOBO
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


            $buyerId = (int)(
                $escrow['buyer_id']
                ?? 0
            );


            if ($buyerId > 0) {

                if (
                    !$notification->exists(
                        $buyerId,
                        'escrow_paid',
                        $paymentReference
                    )
                ) {

                    $notification->create(
                        $buyerId,
                        'escrow_paid',
                        'Escrow Payment Received',
                        $buyerMessage,
                        $paymentReference
                    );
                }
            }


            $sellerId = (int)(
                $escrow['seller_id']
                ?? 0
            );


            if ($sellerId > 0) {

                if (
                    !$notification->exists(
                        $sellerId,
                        'escrow_paid',
                        $paymentReference
                    )
                ) {

                    $notification->create(
                        $sellerId,
                        'escrow_paid',
                        'Buyer Payment Received',
                        $sellerMessage,
                        $paymentReference
                    );
                }
            }


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'NOTIFICATIONS_QUEUED',
                    'payment_reference' =>
                        $paymentReference,
                    'buyer_id' =>
                        $buyerId,
                    'seller_id' =>
                        $sellerId,
                ]
            );


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

            $userModel = new User();


            $buyerId = (int)(
                $escrow['buyer_id']
                ?? 0
            );


            if ($buyerId > 0) {

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


            $sellerId = (int)(
                $escrow['seller_id']
                ?? 0
            );


            if ($sellerId > 0) {

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


    /**
     * ---------------------------------------------------------
     * STANDARD FAILURE RESPONSE
     * ---------------------------------------------------------
     */
    protected function failure(
        string $message,
        string $step
    ): array {

        Logger::write(
            'paystack_escrow_payment_error',
            [
                'step' =>
                    $step,
            ]
        );


        return [
            'success' => false,
            'message' => $message,
        ];
    }
}