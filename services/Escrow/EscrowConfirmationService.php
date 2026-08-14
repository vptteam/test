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
        $this->escrowModel =
            new Escrow();

        $this->botNotification =
            new BotNotification();

        $this->conversation =
            new Conversation();

        Logger::write(
            'escrow_confirmation_service',
            [
                'step' => 'CONSTRUCTOR',
            ]
        );
    }

    /**
     * ---------------------------------------------------------
     * Confirm Buyer Receipt
     * ---------------------------------------------------------
     *
     * This is the shared confirmation operation used by:
     *
     * - API
     * - Telegram
     * - WhatsApp
     * - SMS
     * - USSD
     *
     * Canonical state transition:
     *
     *     item_sent
     *          ↓
     *     awaiting_payout
     *
     * The actual state transition belongs to:
     *
     *     Escrow::buyerConfirm()
     *
     * This service coordinates authorization, validation,
     * workflow completion and notifications.
     *
     * ---------------------------------------------------------
     */
    public function confirm(
        string $reference,
        int $buyerId
    ): array {

        $reference =
            strtoupper(
                trim($reference)
            );

        try {

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step' =>
                        'CONFIRM_START',

                    'reference' =>
                        $reference,

                    'buyer_id' =>
                        $buyerId,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Validate Reference
            |--------------------------------------------------------------------------
            */

            if ($reference === '') {

                return $this->failure(
                    'REFERENCE_REQUIRED',
                    'Escrow reference is required.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Buyer
            |--------------------------------------------------------------------------
            */

            if ($buyerId <= 0) {

                return $this->failure(
                    'BUYER_REQUIRED',
                    'Buyer identification is required.',
                    $reference
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Load Escrow
            |--------------------------------------------------------------------------
            */

            $escrow =
                $this->escrowModel
                    ->findByReference(
                        $reference
                    );

            if (
                !is_array($escrow)
                ||
                $escrow === []
            ) {

                return $this->failure(
                    'ESCROW_NOT_FOUND',
                    'Escrow transaction not found.',
                    $reference
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Extract Core Data
            |--------------------------------------------------------------------------
            */

            $escrowId =
                (int)(
                    $escrow['id']
                    ?? 0
                );

            $escrowBuyerId =
                (int)(
                    $escrow['buyer_id']
                    ?? 0
                );

            $sellerId =
                (int)(
                    $escrow['seller_id']
                    ?? 0
                );

            $status =
                $this->status(
                    $escrow['status']
                    ?? ''
                );

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step' =>
                        'ESCROW_LOADED',

                    'reference' =>
                        $reference,

                    'escrow_id' =>
                        $escrowId,

                    'buyer_id' =>
                        $buyerId,

                    'escrow_buyer_id' =>
                        $escrowBuyerId,

                    'seller_id' =>
                        $sellerId,

                    'status' =>
                        $status,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Validate Escrow Structure
            |--------------------------------------------------------------------------
            */

            if (
                $escrowId <= 0
                ||
                $escrowBuyerId <= 0
                ||
                $sellerId <= 0
            ) {

                return $this->failure(
                    'ESCROW_INVALID',
                    'Escrow transaction data is invalid.',
                    $reference,
                    $escrow
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Verify Buyer Ownership
            |--------------------------------------------------------------------------
            */

            if (
                $escrowBuyerId !== $buyerId
            ) {

                Logger::write(
                    'escrow_confirmation_service_error',
                    [
                        'step' =>
                            'UNAUTHORIZED_BUYER',

                        'reference' =>
                            $reference,

                        'buyer_id' =>
                            $buyerId,

                        'escrow_buyer_id' =>
                            $escrowBuyerId,
                    ]
                );

                return $this->failure(
                    'UNAUTHORIZED_BUYER',
                    'Only the authorized buyer can confirm receipt.',
                    $reference,
                    $escrow
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Already Completed
            |--------------------------------------------------------------------------
            */

            if (
                $status === 'completed'
            ) {

                return $this->failure(
                    'ALREADY_COMPLETED',
                    'This escrow has already been completed.',
                    $reference,
                    $escrow
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Cancelled
            |--------------------------------------------------------------------------
            */

            if (
                $status === 'cancelled'
            ) {

                return $this->failure(
                    'ESCROW_CANCELLED',
                    'This escrow has already been cancelled.',
                    $reference,
                    $escrow
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Already Confirmed
            |--------------------------------------------------------------------------
            |
            | awaiting_payout is the canonical state produced by
            | Escrow::buyerConfirm().
            |
            | buyer_confirmed is accepted for compatibility with
            | older records.
            |
            */

            if (
                in_array(
                    $status,
                    [
                        'awaiting_payout',
                        'buyer_confirmed',
                    ],
                    true
                )
            ) {

                return $this->failure(
                    'ALREADY_CONFIRMED',
                    'Delivery confirmation has already been received.',
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
                $status !== 'item_sent'
            ) {

                Logger::write(
                    'escrow_confirmation_service',
                    [
                        'step' =>
                            'NOT_READY',

                        'reference' =>
                            $reference,

                        'status' =>
                            $status,
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
            |
            | Escrow model owns the actual atomic transition.
            |
            */

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step' =>
                        'BUYER_CONFIRM_TRANSITION',

                    'reference' =>
                        $reference,

                    'escrow_id' =>
                        $escrowId,
                ]
            );

            $confirmed =
                $this->escrowModel
                    ->buyerConfirm(
                        $escrowId
                    );

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step' =>
                        'BUYER_CONFIRM_RESULT',

                    'reference' =>
                        $reference,

                    'escrow_id' =>
                        $escrowId,

                    'result' =>
                        $confirmed,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Concurrent Request Protection
            |--------------------------------------------------------------------------
            */

            if (!$confirmed) {

                $afterRace =
                    $this->escrowModel
                        ->find(
                            $escrowId
                        );

                $afterStatus =
                    $this->status(
                        $afterRace['status']
                        ?? ''
                    );

                /*
                 * Another request may have won the transition.
                 */

                if (
                    is_array($afterRace)
                    &&
                    in_array(
                        $afterStatus,
                        [
                            'awaiting_payout',
                            'buyer_confirmed',
                        ],
                        true
                    )
                ) {

                    Logger::write(
                        'escrow_confirmation_service',
                        [
                            'step' =>
                                'CONCURRENT_CONFIRMATION_DETECTED',

                            'reference' =>
                                $reference,

                            'escrow_id' =>
                                $escrowId,

                            'status' =>
                                $afterStatus,
                        ]
                    );

                    $escrow =
                        $afterRace;

                    $this->runPostConfirmationActions(
                        $escrow,
                        $reference
                    );

                    return [
                        'success' =>
                            true,

                        'code' =>
                            'RECEIPT_ALREADY_CONFIRMED',

                        'message' =>
                            'Delivery confirmation has already been received.',

                        'reference' =>
                            $reference,

                        'escrow' =>
                            $escrow,
                    ];
                }

                return $this->failure(
                    'CONFIRMATION_FAILED',
                    'Unable to confirm delivery.',
                    $reference,
                    $escrow
                );
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
                ?:
                $escrow;

            $finalStatus =
                $this->status(
                    $escrow['status']
                    ?? ''
                );

            /*
            |--------------------------------------------------------------------------
            | Verify Final State
            |--------------------------------------------------------------------------
            */

            if (
                !in_array(
                    $finalStatus,
                    [
                        'awaiting_payout',
                        'buyer_confirmed',
                    ],
                    true
                )
            ) {

                Logger::write(
                    'escrow_confirmation_service_error',
                    [
                        'step' =>
                            'POST_CONFIRMATION_STATE_INVALID',

                        'reference' =>
                            $reference,

                        'escrow_id' =>
                            $escrowId,

                        'status' =>
                            $finalStatus,
                    ]
                );

                return $this->failure(
                    'STATE_CONFIRMATION_FAILED',
                    'Delivery was confirmed but the escrow state could not be verified.',
                    $reference,
                    $escrow
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Post Confirmation Actions
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | These actions must never undo the database state transition.
            |
            */

            $this->runPostConfirmationActions(
                $escrow,
                $reference
            );

            /*
            |--------------------------------------------------------------------------
            | Complete
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step' =>
                        'CONFIRM_COMPLETE',

                    'reference' =>
                        $reference,

                    'escrow_id' =>
                        $escrowId,

                    'buyer_id' =>
                        $buyerId,

                    'seller_id' =>
                        $sellerId,

                    'status' =>
                        $finalStatus,
                ]
            );

            return [
                'success' =>
                    true,

                'code' =>
                    'RECEIPT_CONFIRMED',

                'message' =>
                    'Receipt confirmed successfully. The seller payout workflow has been started.',

                'reference' =>
                    $reference,

                'escrow' =>
                    $escrow,
            ];

        } catch (Throwable $e) {

            Logger::write(
                'escrow_confirmation_service_error',
                [
                    'step' =>
                        'CONFIRM_EXCEPTION',

                    'reference' =>
                        $reference,

                    'buyer_id' =>
                        $buyerId,

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

                'code' =>
                    'SYSTEM_ERROR',

                'message' =>
                    'Unable to confirm receipt at this time.',

                'reference' =>
                    $reference !== ''
                    ? $reference
                    : null,

                'escrow' =>
                    null,
            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * Post Confirmation Actions
     * ---------------------------------------------------------
     */
    protected function runPostConfirmationActions(
        array $escrow,
        string $reference
    ): void {

        $buyerId =
            (int)(
                $escrow['buyer_id']
                ?? 0
            );

        $sellerId =
            (int)(
                $escrow['seller_id']
                ?? 0
            );

        /*
         * The escrow state has already been changed.
         *
         * Therefore failures here are logged but do not cause
         * the confirmation itself to fail.
         */

        $this->finishBuyerWorkflow(
            $buyerId
        );

        $this->startSellerBankWorkflow(
            $sellerId
        );

        $this->notifySeller(
            $escrow,
            $reference
        );

        $this->notifyBuyer(
            $escrow,
            $reference
        );

        $adminId =
            defined('ESCROW_ADMIN_ID')
            ? (int)ESCROW_ADMIN_ID
            : 1;

        if ($adminId > 0) {

            $this->notifyAdmin(
                $adminId,
                $reference
            );
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

        if ($buyerId <= 0) {
            return;
        }

        try {

            $conversation =
                $this->conversation
                    ->active(
                        $buyerId
                    );

            if (
                !is_array($conversation)
                ||
                empty($conversation['id'])
            ) {

                return;
            }

            $this->conversation->finish(
                (int)$conversation['id']
            );

            Logger::write(
                'escrow_confirmation_service',
                [
                    'step' =>
                        'BUYER_WORKFLOW_FINISHED',

                    'buyer_id' =>
                        $buyerId,

                    'conversation_id' =>
                        (int)$conversation['id'],
                ]
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_confirmation_service_error',
                [
                    'step' =>
                        'BUYER_WORKFLOW_FAILED',

                    'buyer_id' =>
                        $buyerId,

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
     * Start Seller Bank Workflow
     * ---------------------------------------------------------
     */
    protected function startSellerBankWorkflow(
        int $sellerId
    ): void {

        if ($sellerId <= 0) {
            return;
        }

        try {

            $conversation =
                $this->conversation
                    ->active(
                        $sellerId
                    );

            if (
                is_array($conversation)
                &&
                !empty($conversation['id'])
            ) {

                $this->conversation->finish(
                    (int)$conversation['id']
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
                    'step' =>
                        'SELLER_BANK_WORKFLOW_STARTED',

                    'seller_id' =>
                        $sellerId,
                ]
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_confirmation_service_error',
                [
                    'step' =>
                        'SELLER_BANK_WORKFLOW_FAILED',

                    'seller_id' =>
                        $sellerId,

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
     * Notify Seller
     * ---------------------------------------------------------
     */
    protected function notifySeller(
        array $escrow,
        string $reference
    ): void {

        $sellerId =
            (int)(
                $escrow['seller_id']
                ?? 0
            );

        if ($sellerId <= 0) {
            return;
        }

        try {

            $notificationReference =
                $reference .
                '_BUYER_CONFIRMED';

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

                    "The buyer has confirmed receiving your item.\n\n" .
                    "Escrow Reference:\n" .
                    "{$reference}\n\n" .
                    "Before payment can be released, you must register a payout bank account.\n\n" .
                    "Reply:\n" .
                    "BANK BANK CODE YOUR ACCOUNT NUMBER\n\n" .
                    "or\n\n" .
                    "BANKS",

                    $notificationReference
                );
            }

        } catch (Throwable $e) {

            Logger::write(
                'escrow_confirmation_service_error',
                [
                    'step' =>
                        'SELLER_NOTIFICATION_FAILED',

                    'seller_id' =>
                        $sellerId,

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

        $buyerId =
            (int)(
                $escrow['buyer_id']
                ?? 0
            );

        if ($buyerId <= 0) {
            return;
        }

        try {

            $notificationReference =
                $reference .
                '_DELIVERY_CONFIRMED';

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

                    "Your delivery confirmation has been received.\n\n" .
                    "Escrow Reference:\n" .
                    "{$reference}\n\n" .
                    "The seller payout process has now been started.",

                    $notificationReference
                );
            }

        } catch (Throwable $e) {

            Logger::write(
                'escrow_confirmation_service_error',
                [
                    'step' =>
                        'BUYER_NOTIFICATION_FAILED',

                    'buyer_id' =>
                        $buyerId,

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

        if ($adminId <= 0) {
            return;
        }

        try {

            $notificationReference =
                $reference .
                '_ADMIN_REVIEW';

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

                    "Buyer has confirmed delivery.\n\n" .
                    "Escrow Reference:\n" .
                    "{$reference}\n\n" .
                    "Status:\n" .
                    "Awaiting payout review.\n\n" .
                    "Seller payout bank verification is required before release.",

                    $notificationReference
                );
            }

        } catch (Throwable $e) {

            Logger::write(
                'escrow_confirmation_service_error',
                [
                    'step' =>
                        'ADMIN_NOTIFICATION_FAILED',

                    'admin_id' =>
                        $adminId,

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
        }
    }


    /**
     * ---------------------------------------------------------
     * Normalize Status
     * ---------------------------------------------------------
     */
    protected function status(
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
                'step' =>
                    'FAILURE',

                'code' =>
                    $code,

                'message' =>
                    $message,

                'reference' =>
                    $reference,
            ]
        );

        return [
            'success' =>
                false,

            'code' =>
                $code,

            'message' =>
                $message,

            'reference' =>
                $reference,

            'escrow' =>
                $escrow,
        ];
    }
}