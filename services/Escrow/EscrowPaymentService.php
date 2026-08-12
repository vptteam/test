<?php

declare(strict_types=1);

namespace Services\Escrow;

use Core\Logger;
use Models\BotNotification;
use Models\User;
use Modules\Escrow\Models\Escrow;
use Throwable;

class EscrowPaymentService
{
    protected Escrow $escrowModel;

    protected User $userModel;

    protected BotNotification $botNotification;


    /**
     * ---------------------------------------------------------
     * Constructor
     * ---------------------------------------------------------
     */
    public function __construct()
    {
        $this->escrowModel =
            new Escrow();

        $this->userModel =
            new User();

        $this->botNotification =
            new BotNotification();

        Logger::write(
            'escrow_payment_service',
            [
                'step' => 'CONSTRUCTOR'
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * Mark Escrow Payment As Received
     * ---------------------------------------------------------
     *
     * This is the shared escrow payment operation.
     *
     * It is deliberately independent of:
     *
     * - Telegram
     * - WhatsApp
     * - SMS
     * - USSD
     * - Website
     * - Mobile App
     *
     * Those interfaces should eventually call this service.
     *
     * ---------------------------------------------------------
     */
    public function markPaid(
        int $escrowId,
        string $paymentReference,
        array $transaction = []
    ): array {

        try {

            Logger::write(
                'escrow_payment_service',
                [
                    'step' => 'MARK_PAID_START',
                    'escrow_id' => $escrowId,
                    'payment_reference' => $paymentReference
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Validate Inputs
            |--------------------------------------------------------------------------
            */

            $escrowId =
                (int)$escrowId;

            $paymentReference =
                trim($paymentReference);


            if ($escrowId <= 0) {

                return [
                    'success' => false,
                    'message' => 'Invalid escrow ID.'
                ];
            }


            if ($paymentReference === '') {

                return [
                    'success' => false,
                    'message' => 'Payment reference is required.'
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


            Logger::write(
                'escrow_payment_service',
                [
                    'step' => 'ESCROW_LOOKUP',
                    'escrow_id' => $escrowId,
                    'found' => $escrow !== null
                ]
            );


            if (!$escrow) {

                return [
                    'success' => false,
                    'message' => 'Escrow transaction not found.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Existing Status
            |--------------------------------------------------------------------------
            |
            | Payment has already been accepted.
            |
            */

            $status =
                (string)(
                    $escrow['status']
                    ?? ''
                );


            if (
                in_array(
                    $status,
                    [
                        'paid',
                        'item_sent',
                        'buyer_confirmed',
                        'awaiting_payout',
                        'completed'
                    ],
                    true
                )
            ) {

                Logger::write(
                    'escrow_payment_service',
                    [
                        'step' => 'ALREADY_PAID',
                        'escrow_id' => $escrowId,
                        'status' => $status
                    ]
                );

                return [
                    'success' => true,
                    'already_paid' => true,
                    'message' => 'Escrow payment has already been recorded.',
                    'reference' =>
                        $escrow['reference']
                        ?? null,
                    'status' => $status
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Paystack Transaction Reference
            |--------------------------------------------------------------------------
            */

            if (
                isset($transaction['reference'])
                &&
                trim(
                    (string)$transaction['reference']
                ) !== ''
            ) {

                $transactionReference =
                    trim(
                        (string)$transaction['reference']
                    );

                if (
                    $transactionReference
                    !==
                    $paymentReference
                ) {

                    Logger::write(
                        'escrow_payment_service',
                        [
                            'step' => 'REFERENCE_MISMATCH',
                            'expected' => $paymentReference,
                            'transaction_reference' =>
                                $transactionReference
                        ]
                    );

                    return [
                        'success' => false,
                        'message' =>
                            'Payment reference mismatch.'
                    ];
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Mark Escrow Paid
            |--------------------------------------------------------------------------
            */

            $paid =
                $this->escrowModel->markPaid(
                    $escrowId,
                    $paymentReference
                );


            Logger::write(
                'escrow_payment_service',
                [
                    'step' => 'MARK_PAID_RESULT',
                    'escrow_id' => $escrowId,
                    'payment_reference' =>
                        $paymentReference,
                    'result' => $paid
                ]
            );


            if (!$paid) {

                return [
                    'success' => false,
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
                );


            if (!$escrow) {

                return [
                    'success' => false,
                    'message' =>
                        'Escrow could not be reloaded after payment.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Prepare Notifications
            |--------------------------------------------------------------------------
            */

            $buyerMessage =
                "Your payment has been received.\n\n"
                . "The seller has been notified immediately.\n\n"
                . "After you receive your item, reply:\n\n"
                . "RECEIVED "
                . ($escrow['reference'] ?? '');


            $sellerMessage =
                "A buyer has successfully paid.\n\n"
                . "Escrow Reference:\n"
                . ($escrow['reference'] ?? '')
                . "\n\n"
                . "Please deliver the item to the buyer.\n\n"
                . "After dispatching the item reply:\n\n"
                . "SHIP "
                . ($escrow['reference'] ?? '');


            /*
            |--------------------------------------------------------------------------
            | Queue Buyer Notification
            |--------------------------------------------------------------------------
            */

            $this->queueBuyerNotification(
                $escrow,
                $paymentReference,
                $buyerMessage
            );


            /*
            |--------------------------------------------------------------------------
            | Queue Seller Notification
            |--------------------------------------------------------------------------
            */

            $this->queueSellerNotification(
                $escrow,
                $paymentReference,
                $sellerMessage
            );


            /*
            |--------------------------------------------------------------------------
            | Immediate Platform Notifications
            |--------------------------------------------------------------------------
            */

            $this->sendImmediateNotifications(
                $escrow,
                $buyerMessage,
                $sellerMessage
            );


            Logger::write(
                'escrow_payment_service',
                [
                    'step' => 'MARK_PAID_COMPLETE',
                    'escrow_id' => $escrowId,
                    'reference' =>
                        $escrow['reference']
                        ?? null,
                    'payment_reference' =>
                        $paymentReference
                ]
            );


            return [
                'success' => true,
                'already_paid' => false,
                'message' =>
                    'Escrow payment recorded successfully.',
                'reference' =>
                    $escrow['reference']
                    ?? null,
                'status' =>
                    $escrow['status']
                    ?? 'paid'
            ];

        }
        catch (Throwable $e) {

            Logger::write(
                'escrow_payment_service_error',
                [
                    'step' =>
                        'MARK_PAID_EXCEPTION',

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

                    'trace' =>
                        $e->getTraceAsString()
                ]
            );


            return [
                'success' => false,
                'message' =>
                    'Unable to process escrow payment.'
            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * Queue Buyer Notification
     * ---------------------------------------------------------
     */
    protected function queueBuyerNotification(
        array $escrow,
        string $paymentReference,
        string $message
    ): void {

        try {

            $buyerId =
                (int)(
                    $escrow['buyer_id']
                    ?? 0
                );


            if ($buyerId <= 0) {
                return;
            }


            if (
                !$this->botNotification->exists(
                    $buyerId,
                    'escrow_paid',
                    $paymentReference
                )
            ) {

                $this->botNotification->create(
                    $buyerId,
                    'escrow_paid',
                    'Escrow Payment Received',
                    $message,
                    $paymentReference
                );


                Logger::write(
                    'escrow_payment_service',
                    [
                        'step' =>
                            'BUYER_NOTIFICATION_CREATED',

                        'buyer_id' =>
                            $buyerId
                    ]
                );
            }

        }
        catch (Throwable $e) {

            Logger::write(
                'escrow_payment_service_error',
                [
                    'step' =>
                        'BUYER_NOTIFICATION_FAILED',

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
     * Queue Seller Notification
     * ---------------------------------------------------------
     */
    protected function queueSellerNotification(
        array $escrow,
        string $paymentReference,
        string $message
    ): void {

        try {

            $sellerId =
                (int)(
                    $escrow['seller_id']
                    ?? 0
                );


            if ($sellerId <= 0) {
                return;
            }


            if (
                !$this->botNotification->exists(
                    $sellerId,
                    'escrow_paid',
                    $paymentReference
                )
            ) {

                $this->botNotification->create(
                    $sellerId,
                    'escrow_paid',
                    'Buyer Payment Received',
                    $message,
                    $paymentReference
                );


                Logger::write(
                    'escrow_payment_service',
                    [
                        'step' =>
                            'SELLER_NOTIFICATION_CREATED',

                        'seller_id' =>
                            $sellerId
                    ]
                );
            }

        }
        catch (Throwable $e) {

            Logger::write(
                'escrow_payment_service_error',
                [
                    'step' =>
                        'SELLER_NOTIFICATION_FAILED',

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
     * Immediate Buyer / Seller Messages
     * ---------------------------------------------------------
     */
    protected function sendImmediateNotifications(
        array $escrow,
        string $buyerMessage,
        string $sellerMessage
    ): void {

        try {

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


            if ($buyerId > 0) {

                $buyer =
                    $this->userModel->find(
                        $buyerId
                    );


                if ($buyer) {

                    $reply =
                        \Core\ReplyFactory::make(
                            $buyer['platform']
                        );


                    $reply->text(
                        $buyer['platform_id'],
                        $buyerMessage
                    );


                    Logger::write(
                        'escrow_payment_service',
                        [
                            'step' =>
                                'BUYER_MESSAGE_SENT',

                            'buyer_id' =>
                                $buyerId
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


            if ($sellerId > 0) {

                $seller =
                    $this->userModel->find(
                        $sellerId
                    );


                if ($seller) {

                    $reply =
                        \Core\ReplyFactory::make(
                            $seller['platform']
                        );


                    $reply->text(
                        $seller['platform_id'],
                        $sellerMessage
                    );


                    Logger::write(
                        'escrow_payment_service',
                        [
                            'step' =>
                                'SELLER_MESSAGE_SENT',

                            'seller_id' =>
                                $sellerId
                        ]
                    );
                }
            }

        }
        catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | Notification Failure Must Not Undo Payment
            |--------------------------------------------------------------------------
            |
            | The escrow has already been marked paid.
            |
            | Therefore a messaging failure should be logged rather
            | than reported as a payment failure.
            |
            */

            Logger::write(
                'escrow_payment_service_error',
                [
                    'step' =>
                        'LIVE_NOTIFICATION_FAILED',

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