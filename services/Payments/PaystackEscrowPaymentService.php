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
                $transaction === []
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

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PROCESS_REFERENCE_MISSING'
                    ]
                );

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

                    'reference' =>
                        $reference,

                    'metadata' =>
                        $metadata
                ]
            );


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


            if (
                $paymentType !== 'escrow'
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


            if ($escrowId <= 0) {

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

            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'PROCESS_ESCROW_LOOKUP',

                    'escrow_id' =>
                        $escrowId,

                    'reference' =>
                        $reference
                ]
            );


            $escrow =
                $this->escrowModel->find(
                    $escrowId
                );


            if (
                !is_array($escrow)
                ||
                $escrow === []
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

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PROCESS_ESCROW_REFERENCE_MISSING',

                        'escrow_id' =>
                            $escrowId
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
            | Prevent Metadata Reference Mismatch
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
                            $reference,

                        'metadata_reference' =>
                            $metadataEscrowReference,

                        'database_reference' =>
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
            | Paystack Reference Must Match Escrow Reference
            |--------------------------------------------------------------------------
            */

            if (
                strtoupper($reference)
                !==
                $escrowReference
            ) {

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'PAYSTACK_REFERENCE_MISMATCH',

                        'paystack_reference' =>
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
            | Verify Amount
            |--------------------------------------------------------------------------
            |
            | Paystack returns amount in kobo.
            |
            | Escrow amount is expected in naira.
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


            $paystackAmountNaira =
                $paystackAmountKobo > 0
                ? $paystackAmountKobo / 100
                : 0;


            Logger::write(
                'paystack_escrow_payment',
                [
                    'step' =>
                        'AMOUNT_VALIDATION',

                    'escrow_id' =>
                        $escrowId,

                    'reference' =>
                        $reference,

                    'escrow_amount' =>
                        $escrowAmount,

                    'paystack_amount_kobo' =>
                        $paystackAmountKobo,

                    'paystack_amount_naira' =>
                        $paystackAmountNaira
                ]
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
            | Compare Using Kobo
            |--------------------------------------------------------------------------
            |
            | Avoid floating-point comparison.
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
                            'AMOUNT_MISMATCH',

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
            | Duplicate Protection
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
                            'ALREADY_PROCESSED',

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
                        'MARK_PAID_START',

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
                        'MARK_PAID_RESULT',

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

                Logger::write(
                    'paystack_escrow_payment_error',
                    [
                        'step' =>
                            'MARK_PAID_FAILED',

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
            | Messages
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
                        $escrowReference
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

        }
        catch (Throwable $e) {

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


  protected function extractEscrowAmount(
    array $escrow
): float {
    /*
    |--------------------------------------------------------------------------
    | total_amount is the authoritative buyer payment amount.
    |--------------------------------------------------------------------------
    |
    | amount       = underlying item/escrow amount
    | escrow_fee   = platform escrow fee
    | paystack_fee = payment processing fee
    | total_amount = amount actually charged to buyer
    |
    */

    if (
        isset($escrow['total_amount'])
        &&
        is_numeric($escrow['total_amount'])
        &&
        (float)$escrow['total_amount'] > 0
    ) {
        return (float)$escrow['total_amount'];
    }

    /*
    |--------------------------------------------------------------------------
    | Backward compatibility
    |--------------------------------------------------------------------------
    */

    if (
        isset($escrow['amount'])
        &&
        is_numeric($escrow['amount'])
        &&
        (float)$escrow['amount'] > 0
    ) {
        return (float)$escrow['amount'];
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