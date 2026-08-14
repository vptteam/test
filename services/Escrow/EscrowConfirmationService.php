<?php

declare(strict_types=1);

namespace Services\Escrow;

use Core\Logger;
use Models\BotNotification;
use Models\Conversation;
use Models\Escrow;
use Throwable;

/**
 * Single source of truth for buyer receipt confirmation.
 *
 * Flow:
 *
 * item_sent
 *     -> buyer_confirmed
 *     -> seller payout/bank workflow
 *
 * This service does not pay the seller and does not contain Paystack logic.
 */
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

        Logger::write('escrow_confirmation_service', [
            'step' => 'CONSTRUCTOR',
        ]);
    }

    /**
     * Confirm that the authorized buyer received the item.
     */
    public function confirm(string $reference, int $buyerId): array
    {
        $reference = strtoupper(trim($reference));

        try {
            Logger::write('escrow_confirmation_service', [
                'step' => 'CONFIRM_START',
                'reference' => $reference,
                'buyer_id' => $buyerId,
            ]);

            if ($reference === '') {
                return $this->failure('REFERENCE_REQUIRED', 'Escrow reference is required.');
            }

            if ($buyerId <= 0) {
                return $this->failure('BUYER_REQUIRED', 'Buyer identification is required.', $reference);
            }

            $escrow = $this->escrowModel->findByReference($reference);

            if (!is_array($escrow) || empty($escrow)) {
                return $this->failure('ESCROW_NOT_FOUND', 'Escrow transaction not found.', $reference);
            }

            $escrowId = (int)($escrow['id'] ?? 0);
            $expectedBuyerId = (int)($escrow['buyer_id'] ?? 0);
            $status = strtolower(trim((string)($escrow['status'] ?? '')));

            Logger::write('escrow_confirmation_service', [
                'step' => 'ESCROW_LOADED',
                'reference' => $reference,
                'escrow_id' => $escrowId,
                'buyer_id' => $buyerId,
                'expected_buyer_id' => $expectedBuyerId,
                'status' => $status,
            ]);

            if ($escrowId <= 0) {
                return $this->failure('ESCROW_INVALID', 'Escrow transaction is invalid.', $reference, $escrow);
            }

            if ($expectedBuyerId !== $buyerId) {
                return $this->failure(
                    'UNAUTHORIZED_BUYER',
                    'Only the authorized buyer can confirm receipt.',
                    $reference,
                    $escrow
                );
            }

            /* Idempotent success: the confirmation was already accepted. */
            if ($status === 'buyer_confirmed') {
                $this->ensureExistingConfirmationNotifications($escrow, $reference);

                return [
                    'success' => true,
                    'code' => 'ALREADY_CONFIRMED',
                    'message' => 'Receipt has already been confirmed.',
                    'reference' => $reference,
                    'escrow' => $escrow,
                    'already_processed' => true,
                ];
            }

            if ($status === 'completed') {
                return $this->failure('ALREADY_COMPLETED', 'This escrow has already been completed.', $reference, $escrow);
            }

            if ($status === 'cancelled') {
                return $this->failure('ESCROW_CANCELLED', 'This escrow has already been cancelled.', $reference, $escrow);
            }

            if ($status !== 'item_sent') {
                return $this->failure(
                    'NOT_READY',
                    'The seller has not marked this item as shipped yet.',
                    $reference,
                    $escrow
                );
            }

            /*
             * The model performs the atomic state transition:
             * item_sent -> buyer_confirmed.
             */
            $confirmed = $this->escrowModel->buyerConfirm($escrowId);

            Logger::write('escrow_confirmation_service', [
                'step' => 'BUYER_CONFIRM_RESULT',
                'reference' => $reference,
                'escrow_id' => $escrowId,
                'result' => $confirmed,
            ]);

            if (!$confirmed) {
                /* A concurrent request may have won the race. */
                $afterRace = $this->escrowModel->find($escrowId);
                $raceStatus = strtolower(trim((string)($afterRace['status'] ?? '')));

                if ($raceStatus === 'buyer_confirmed') {
                    $this->ensurePostConfirmationSideEffects($afterRace, $reference);

                    return [
                        'success' => true,
                        'code' => 'ALREADY_CONFIRMED',
                        'message' => 'Receipt has already been confirmed.',
                        'reference' => $reference,
                        'escrow' => $afterRace,
                        'already_processed' => true,
                    ];
                }

                return $this->failure(
                    'CONFIRMATION_FAILED',
                    'Unable to confirm delivery.',
                    $reference,
                    $escrow
                );
            }

            $escrow = $this->escrowModel->find($escrowId);

            if (!is_array($escrow)) {
                return $this->failure(
                    'ESCROW_RELOAD_FAILED',
                    'Receipt was confirmed but the escrow could not be reloaded.',
                    $reference
                );
            }

            $finalStatus = strtolower(trim((string)($escrow['status'] ?? '')));

            if ($finalStatus !== 'buyer_confirmed') {
                Logger::write('escrow_confirmation_service_error', [
                    'step' => 'UNEXPECTED_POST_CONFIRM_STATUS',
                    'reference' => $reference,
                    'escrow_id' => $escrowId,
                    'status' => $finalStatus,
                ]);

                return $this->failure(
                    'INVALID_CONFIRMATION_STATE',
                    'Receipt confirmation did not reach the expected escrow state.',
                    $reference,
                    $escrow
                );
            }

            $this->ensurePostConfirmationSideEffects($escrow, $reference);

            Logger::write('escrow_confirmation_service', [
                'step' => 'CONFIRM_COMPLETE',
                'reference' => $reference,
                'escrow_id' => $escrowId,
                'buyer_id' => $buyerId,
                'seller_id' => (int)($escrow['seller_id'] ?? 0),
                'status' => $finalStatus,
            ]);

            return [
                'success' => true,
                'code' => 'RECEIPT_CONFIRMED',
                'message' => 'Receipt confirmed successfully. The seller payout workflow has been started.',
                'reference' => $reference,
                'escrow' => $escrow,
                'already_processed' => false,
            ];
        } catch (Throwable $e) {
            Logger::write('escrow_confirmation_service_error', [
                'step' => 'CONFIRM_EXCEPTION',
                'reference' => $reference,
                'buyer_id' => $buyerId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'code' => 'SYSTEM_ERROR',
                'message' => 'Unable to confirm receipt at this time.',
                'reference' => $reference,
                'escrow' => null,
            ];
        }
    }

    protected function ensureExistingConfirmationNotifications(array $escrow, string $reference): void
    {
        $adminId = defined('ESCROW_ADMIN_ID') ? (int)ESCROW_ADMIN_ID : 1;
        $this->notifyBuyer($escrow, $reference);
        $this->notifySeller($escrow, $reference);
        if ($adminId > 0) {
            $this->notifyAdmin($adminId, $reference);
        }
    }

    /**
     * Run the non-financial side effects after a new confirmation.
     * Each operation is independently idempotent/best-effort.
     */
    protected function ensurePostConfirmationSideEffects(array $escrow, string $reference): void
    {
        $buyerId = (int)($escrow['buyer_id'] ?? 0);
        $sellerId = (int)($escrow['seller_id'] ?? 0);
        $adminId = defined('ESCROW_ADMIN_ID') ? (int)ESCROW_ADMIN_ID : 1;

        if ($buyerId > 0) {
            $this->finishBuyerWorkflow($buyerId);
            $this->notifyBuyer($escrow, $reference);
        }

        if ($sellerId > 0) {
            $this->startSellerBankWorkflow($sellerId);
            $this->notifySeller($escrow, $reference);
        }

        if ($adminId > 0) {
            $this->notifyAdmin($adminId, $reference);
        }
    }

    protected function finishBuyerWorkflow(int $buyerId): void
    {
        try {
            $conversation = $this->conversation->active($buyerId);

            if (!is_array($conversation) || empty($conversation['id'])) {
                return;
            }

            $this->conversation->finish((int)$conversation['id']);

            Logger::write('escrow_confirmation_service', [
                'step' => 'BUYER_WORKFLOW_FINISHED',
                'buyer_id' => $buyerId,
                'conversation_id' => (int)$conversation['id'],
            ]);
        } catch (Throwable $e) {
            Logger::write('escrow_confirmation_service_error', [
                'step' => 'BUYER_WORKFLOW_FAILED',
                'buyer_id' => $buyerId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    protected function startSellerBankWorkflow(int $sellerId): void
    {
        try {
            $conversation = $this->conversation->active($sellerId);

            if (is_array($conversation) && !empty($conversation['id'])) {
                $this->conversation->finish((int)$conversation['id']);
            }

            $this->conversation->start($sellerId, 'Escrow', 'BANK', 'BANK');

            Logger::write('escrow_confirmation_service', [
                'step' => 'SELLER_BANK_WORKFLOW_STARTED',
                'seller_id' => $sellerId,
            ]);
        } catch (Throwable $e) {
            Logger::write('escrow_confirmation_service_error', [
                'step' => 'SELLER_BANK_WORKFLOW_FAILED',
                'seller_id' => $sellerId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    protected function notifySeller(array $escrow, string $reference): void
    {
        try {
            $sellerId = (int)($escrow['seller_id'] ?? 0);
            if ($sellerId <= 0) return;

            $type = 'escrow_buyer_confirmed';
            $notificationReference = $reference . '_BUYER_CONFIRMED';

            $this->botNotification->create(
                $sellerId,
                $type,
                'Buyer Confirmed Delivery',
                "The buyer has confirmed receiving your item.\n\n" .
                "Escrow Reference:\n{$reference}\n\n" .
                "Before payment can be released, register a payout bank account.\n\n" .
                "Reply:\nBANK BANK CODE YOUR ACCOUNT NUMBER\n\n" .
                "or\nBANKS",
                $notificationReference
            );
        } catch (Throwable $e) {
            Logger::write('escrow_confirmation_service_error', [
                'step' => 'SELLER_NOTIFICATION_FAILED',
                'reference' => $reference,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    protected function notifyBuyer(array $escrow, string $reference): void
    {
        try {
            $buyerId = (int)($escrow['buyer_id'] ?? 0);
            if ($buyerId <= 0) return;

            $this->botNotification->create(
                $buyerId,
                'escrow_delivery_confirmed',
                'Delivery Confirmed',
                "Your delivery confirmation has been received.\n\n" .
                "Escrow Reference:\n{$reference}\n\n" .
                "The seller payout process has now been started.",
                $reference . '_DELIVERY_CONFIRMED'
            );
        } catch (Throwable $e) {
            Logger::write('escrow_confirmation_service_error', [
                'step' => 'BUYER_NOTIFICATION_FAILED',
                'reference' => $reference,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    protected function notifyAdmin(int $adminId, string $reference): void
    {
        try {
            if ($adminId <= 0) return;

            $this->botNotification->create(
                $adminId,
                'escrow_admin_review',
                'Escrow Awaiting Review',
                "Buyer has confirmed delivery.\n\n" .
                "Escrow Reference:\n{$reference}\n\n" .
                "Status:\nAwaiting payout review.\n\n" .
                "Seller payout bank verification is required before release.",
                $reference . '_ADMIN_REVIEW'
            );
        } catch (Throwable $e) {
            Logger::write('escrow_confirmation_service_error', [
                'step' => 'ADMIN_NOTIFICATION_FAILED',
                'reference' => $reference,
                'admin_id' => $adminId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    protected function failure(
        string $code,
        string $message,
        ?string $reference = null,
        ?array $escrow = null
    ): array {
        Logger::write('escrow_confirmation_service', [
            'step' => 'FAILURE',
            'code' => $code,
            'message' => $message,
            'reference' => $reference,
        ]);

        return [
            'success' => false,
            'code' => $code,
            'message' => $message,
            'reference' => $reference,
            'escrow' => $escrow,
            'already_processed' => false,
        ];
    }
}
