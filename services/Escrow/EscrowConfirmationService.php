<?php

declare(strict_types=1);

namespace Services\Escrow;

use Core\Logger;
use Models\BotNotification;
use Models\Conversation;
use Modules\Escrow\Models\Escrow;
use Throwable;

class EscrowConfirmationService
{
    protected Escrow $escrowModel;

    protected BotNotification $botNotification;

    protected Conversation $conversation;

    public function __construct()
    {
        $this->escrowModel = new Escrow();

        $this->botNotification = new BotNotification();

        $this->conversation = new Conversation();

        Logger::write(
            'escrow_confirmation_service',
            [
                'step' => 'CONSTRUCTOR'
            ]
        );
    }

    /**
     * ---------------------------------------------------------
     * Confirm Buyer Receipt
     * ---------------------------------------------------------
     *
     * This is the shared business operation used by:
     *
     * - Telegram
     * - WhatsApp
     * - SMS
     * - USSD
     * - API
     *
     * The caller must provide the internal database buyer ID.
     *
     * Returns:
     *
     * [
     *     'success'   => true|false,
     *     'code'      => string,
     *     'message'   => string,
     *     'reference' => string|null,
     *     'escrow'    => array|null
     * ]
     *
     */
    public function confirm(
        string $reference,
        int $buyerId
    ): array {

        try {

            $reference = strtoupper(
                trim($reference)
            );

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step'       => 'CONFIRM_START',
                    'reference'  => $reference,
                    'buyer_id'   => $buyerId
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Validate Input
            |--------------------------------------------------------------------------
            */

            if ($reference === '') {

                Logger::write(
                    'escrow_confirmation_service',
                    [
                        'step' => 'REFERENCE_EMPTY'
                    ]
                );

                return $this->failure(
                    'REFERENCE_REQUIRED',
                    'Escrow reference is required.'
                );
            }

            if ($buyerId <= 0) {

                Logger::write(
                    'escrow_confirmation_service',
                    [
                        'step'    => 'BUYER_ID_INVALID',
                        'buyer_id' => $buyerId
                    ]
                );

                return $this->failure(
                    'BUYER_REQUIRED',
                    'Buyer identification is required.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Load Escrow
            |--------------------------------------------------------------------------
            */

            $escrow = $this->escrowModel->findByReference(
                $reference
            );

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step'      => 'ESCROW_LOADED',
                    'reference' => $reference,
                    'escrow_id' => $escrow['id'] ?? null,
                    'status'    => $escrow['status'] ?? null
                ]
            );

            if (!$escrow) {

                return $this->failure(
                    'ESCROW_NOT_FOUND',
                    'Escrow transaction not found.',
                    $reference
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Verify Buyer
            |--------------------------------------------------------------------------
            */

            $expectedBuyerId = (int)(
                $escrow['buyer_id'] ?? 0
            );

            if (
                $expectedBuyerId !== $buyerId
            ) {

                Logger::write(
                    'escrow_confirmation_service',
                    [
                        'step'          => 'BUYER_VERIFICATION_FAILED',
                        'reference'     => $reference,
                        'buyer_id'      => $buyerId,
                        'expected_buyer' => $expectedBuyerId
                    ]
                );

                return $this->failure(
                    'UNAUTHORIZED_BUYER',
                    'Only the authorized buyer can confirm receipt.',
                    $reference,
                    $escrow
                );
            }

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step'      => 'BUYER_VERIFIED',
                    'reference' => $reference,
                    'buyer_id'  => $buyerId
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Validate Escrow Status
            |--------------------------------------------------------------------------
            */

            $status = (string)(
                $escrow['status'] ?? ''
            );

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step'      => 'STATUS_CHECK',
                    'reference' => $reference,
                    'status'    => $status
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Already Completed
            |--------------------------------------------------------------------------
            */

            if ($status === 'completed') {

                return $this->failure(
                    'ALREADY_COMPLETED',
                    'This escrow has already been completed.',
                    $reference,
                    $escrow
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Already Awaiting Payout
            |--------------------------------------------------------------------------
            */

            if ($status === 'awaiting_payout') {

                return $this->failure(
                    'ALREADY_CONFIRMED',
                    'Delivery confirmation has already been received.',
                    $reference,
                    $escrow
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Cancelled
            |--------------------------------------------------------------------------
            */

            if ($status === 'cancelled') {

                return $this->failure(
                    'ESCROW_CANCELLED',
                    'This escrow has already been cancelled.',
                    $reference,
                    $escrow
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Seller Must Have Shipped
            |--------------------------------------------------------------------------
            */

            if (
                !in_array(
                    $status,
                    [
                        'item_sent',
                        'seller_confirmed'
                    ],
                    true
                )
            ) {

                Logger::write(
                    'escrow_confirmation_service',
                    [
                        'step'      => 'STATUS_NOT_READY',
                        'reference' => $reference,
                        'status'    => $status
                    ]
                );

                return $this->failure(
                    'NOT_READY',
                    'The seller has not marked this item as shipped yet.',
                    $reference,
                    $escrow
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Buyer Confirmation
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step'      => 'BUYER_CONFIRM_START',
                    'reference' => $reference,
                    'escrow_id' => $escrow['id']
                ]
            );

            $confirmed = $this->escrowModel->buyerConfirm(
                (int)$escrow['id']
            );

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step'      => 'BUYER_CONFIRM_RESULT',
                    'reference' => $reference,
                    'escrow_id' => $escrow['id'],
                    'result'    => $confirmed
                ]
            );

            if (!$confirmed) {

                return $this->failure(
                    'CONFIRMATION_FAILED',
                    'Unable to confirm delivery.',
                    $reference,
                    $escrow
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Reload Escrow
            |--------------------------------------------------------------------------
            */

            $escrow = $this->escrowModel->findByReference(
                $reference
            );

            if (!$escrow) {

                Logger::write(
                    'escrow_confirmation_service',
                    [
                        'step'      => 'ESCROW_RELOAD_FAILED',
                        'reference' => $reference
                    ]
                );

                return $this->failure(
                    'ESCROW_RELOAD_FAILED',
                    'Delivery was confirmed but the escrow could not be reloaded.',
                    $reference
                );
            }

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step'      => 'ESCROW_RELOADED',
                    'reference' => $reference,
                    'status'    => $escrow['status'] ?? null
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Finish Buyer Workflow
            |--------------------------------------------------------------------------
            */

            $this->finishBuyerWorkflow(
                (int)$escrow['buyer_id']
            );

            /*
            |--------------------------------------------------------------------------
            | Start Seller Bank Workflow
            |--------------------------------------------------------------------------
            */

            $this->startSellerBankWorkflow(
                (int)$escrow['seller_id']
            );

            /*
            |--------------------------------------------------------------------------
            | Notifications
            |--------------------------------------------------------------------------
            */

            $this->notifySeller(
                $escrow,
                $reference
            );

            $this->notifyBuyer(
                $escrow,
                $reference
            );

            $adminId = defined('ESCROW_ADMIN_ID')
                ? (int)ESCROW_ADMIN_ID
                : 1;

            $this->notifyAdmin(
                $adminId,
                $reference
            );

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step'      => 'CONFIRM_COMPLETE',
                    'reference' => $reference,
                    'buyer_id'  => $buyerId,
                    'seller_id' => $escrow['seller_id'] ?? null
                ]
            );

            return [
                'success'   => true,
                'code'      => 'RECEIPT_CONFIRMED',
                'message'   => 'Receipt confirmed successfully. The seller payout workflow has been started.',
                'reference' => $reference,
                'escrow'    => $escrow
            ];

        }

        catch (Throwable $e) {

            Logger::write(
                'escrow_confirmation_service_error',
                [
                    'step'      => 'CONFIRM_EXCEPTION',
                    'reference' => $reference ?? null,
                    'buyer_id'  => $buyerId,
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                    'trace'     => $e->getTraceAsString()
                ]
            );

            return [
                'success'   => false,
                'code'      => 'SYSTEM_ERROR',
                'message'   => 'Unable to confirm receipt at this time.',
                'reference' => $reference ?? null,
                'escrow'    => null
            ];
        }
    }

    /**
     * ---------------------------------------------------------
     * Finish Buyer Workflow
     * ---------------------------------------------------------
     */
    protected function finishBuyerWorkflow(
        int $buyerId
    ): void {

        try {

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step'    => 'BUYER_WORKFLOW_START',
                    'buyer_id' => $buyerId
                ]
            );

            $conversation = $this->conversation->active(
                $buyerId
            );

            if (!$conversation) {

                Logger::write(
                    'escrow_confirmation_service',
                    [
                        'step'    => 'NO_BUYER_WORKFLOW',
                        'buyer_id' => $buyerId
                    ]
                );

                return;
            }

            $this->conversation->finish(
                (int)$conversation['id']
            );

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step'            => 'BUYER_WORKFLOW_FINISHED',
                    'conversation_id' => $conversation['id']
                ]
            );

        }

        catch (Throwable $e) {

            Logger::write(
                'escrow_confirmation_service_error',
                [
                    'step'    => 'BUYER_WORKFLOW_FAILED',
                    'buyer_id' => $buyerId,
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine()
                ]
            );
        }
    }

    /**
     * ---------------------------------------------------------
     * Start Seller Bank Workflow
     * ---------------------------------------------------------
     */
    protected function startSellerBankWorkflow(
        int $sellerId
    ): void {

        try {

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step'     => 'SELLER_BANK_WORKFLOW_START',
                    'seller_id' => $sellerId
                ]
            );

            $conversation = $this->conversation->active(
                $sellerId
            );

            if ($conversation) {

                $this->conversation->finish(
                    (int)$conversation['id']
                );

                Logger::write(
                    'escrow_confirmation_service',
                    [
                        'step'            => 'SELLER_PREVIOUS_WORKFLOW_FINISHED',
                        'conversation_id' => $conversation['id']
                    ]
                );
            }

            $this->conversation->start(
                $sellerId,
                'Escrow',
                'BANK',
                'BANK'
            );

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step'     => 'SELLER_BANK_WORKFLOW_STARTED',
                    'seller_id' => $sellerId
                ]
            );

        }

        catch (Throwable $e) {

            Logger::write(
                'escrow_confirmation_service_error',
                [
                    'step'     => 'SELLER_BANK_WORKFLOW_FAILED',
                    'seller_id' => $sellerId,
                    'message'  => $e->getMessage(),
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine()
                ]
            );
        }
    }

    /**
     * ---------------------------------------------------------
     * Notify Seller
     * ---------------------------------------------------------
     */
    protected function notifySeller(
        array $escrow,
        string $reference
    ): void {

        try {

            $sellerId = (int)$escrow['seller_id'];

            $notificationReference =
                $reference . '_BUYER_CONFIRMED';

            if (
                !$this->botNotification->exists(
                    $sellerId,
                    'escrow_buyer_confirmed',
                    $notificationReference
                )
            ) {

                $this->botNotification->create(

                    $sellerId,

                    'escrow_buyer_confirmed',

                    'Buyer Confirmed Delivery',

                    "The buyer has confirmed receiving your item.\n\n".
                    "Escrow Reference:\n".
                    "{$reference}\n\n".
                    "Before payment can be released, you must register a payout bank account.\n\n".
                    "Reply:\n".
                    "BANK BANK CODE YOUR ACCOUNT NUMBER\n\n".
                    "or\n\n".
                    "BANKS",

                    $notificationReference
                );
            }

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step'     => 'SELLER_NOTIFICATION_CREATED',
                    'seller_id' => $sellerId,
                    'reference' => $reference
                ]
            );

        }

        catch (Throwable $e) {

            Logger::write(
                'escrow_confirmation_service_error',
                [
                    'step'     => 'SELLER_NOTIFICATION_FAILED',
                    'reference' => $reference,
                    'message'  => $e->getMessage(),
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine()
                ]
            );
        }
    }

    /**
     * ---------------------------------------------------------
     * Notify Buyer
     * ---------------------------------------------------------
     */
    protected function notifyBuyer(
        array $escrow,
        string $reference
    ): void {

        try {

            $buyerId = (int)$escrow['buyer_id'];

            $notificationReference =
                $reference . '_DELIVERY_CONFIRMED';

            if (
                !$this->botNotification->exists(
                    $buyerId,
                    'escrow_delivery_confirmed',
                    $notificationReference
                )
            ) {

                $this->botNotification->create(

                    $buyerId,

                    'escrow_delivery_confirmed',

                    'Delivery Confirmed',

                    "Your delivery confirmation has been received.\n\n".
                    "Escrow Reference:\n".
                    "{$reference}\n\n".
                    "The seller payout process has now been started.",

                    $notificationReference
                );
            }

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step'    => 'BUYER_NOTIFICATION_CREATED',
                    'buyer_id' => $buyerId,
                    'reference' => $reference
                ]
            );

        }

        catch (Throwable $e) {

            Logger::write(
                'escrow_confirmation_service_error',
                [
                    'step'     => 'BUYER_NOTIFICATION_FAILED',
                    'reference' => $reference,
                    'message'  => $e->getMessage(),
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine()
                ]
            );
        }
    }

    /**
     * ---------------------------------------------------------
     * Notify Admin
     * ---------------------------------------------------------
     */
    protected function notifyAdmin(
        int $adminId,
        string $reference
    ): void {

        try {

            $notificationReference =
                $reference . '_ADMIN_REVIEW';

            if (
                !$this->botNotification->exists(
                    $adminId,
                    'escrow_admin_review',
                    $notificationReference
                )
            ) {

                $this->botNotification->create(

                    $adminId,

                    'escrow_admin_review',

                    'Escrow Awaiting Review',

                    "Buyer has confirmed delivery.\n\n".
                    "Escrow Reference:\n".
                    "{$reference}\n\n".
                    "Status:\n".
                    "Awaiting payout review.\n\n".
                    "Seller payout bank verification is required before release.",

                    $notificationReference
                );
            }

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step'     => 'ADMIN_NOTIFICATION_CREATED',
                    'admin_id' => $adminId,
                    'reference' => $reference
                ]
            );

        }

        catch (Throwable $e) {

            Logger::write(
                'escrow_confirmation_service_error',
                [
                    'step'     => 'ADMIN_NOTIFICATION_FAILED',
                    'reference' => $reference,
                    'admin_id' => $adminId,
                    'message'  => $e->getMessage(),
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine()
                ]
            );
        }
    }

    /**
     * ---------------------------------------------------------
     * Failure Response
     * ---------------------------------------------------------
     */
    protected function failure(
        string $code,
        string $message,
        ?string $reference = null,
        ?array $escrow = null
    ): array {

        Logger::write(
            'escrow_confirmation_service',
            [
                'step'      => 'FAILURE',
                'code'      => $code,
                'message'   => $message,
                'reference' => $reference
            ]
        );

        return [
            'success'   => false,
            'code'      => $code,
            'message'   => $message,
            'reference' => $reference,
            'escrow'    => $escrow
        ];
    }
}
