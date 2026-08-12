<?php

declare(strict_types=1);

namespace Modules\Escrow\Handlers;

use Core\Logger;
use Services\Escrow\EscrowService;
use Models\BotNotification;
use Models\Conversation;
use Throwable;

class SendItemHandler
{
    protected EscrowService $escrow;

    protected BotNotification $notification;

    public function __construct()
    {
        $this->escrow = new EscrowService();
        $this->notification = new BotNotification();
    }

    /**
     * ---------------------------------------------------------
     * Seller marks an item as shipped.
     *
     * Command:
     *
     * SEND ITEM ESCXXXXXXXX
     * ---------------------------------------------------------
     */
    public function start(
        $reply,
        array $user,
        array $message,
        string $text
    ): void {

        try {

            Logger::write(
                'send_item_handler',
                [
                    'step'    => 'START',
                    'user_id' => $user['id'] ?? null,
                    'text'    => $text
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Parse Command
            |--------------------------------------------------------------------------
            */

            $parts = preg_split(
                '/\s+/',
                trim($text)
            );

            if (count($parts) < 3) {

                $reply->text(

                    $message['phone'],

                    "📦 *SEND ITEM*\n\n".
                    "Use:\n\n".
                    "SEND ITEM ESCROW_REFERENCE"

                );

                return;

            }

            $reference = strtoupper(
                trim($parts[2])
            );

            Logger::write(
                'send_item_handler',
                [
                    'step'      => 'REFERENCE_PARSED',
                    'reference' => $reference
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Load Escrow
            |--------------------------------------------------------------------------
            */

            $escrow = $this->escrow->findByReference(
                $reference
            );

            if (!$escrow) {

                Logger::write(
                    'send_item_handler',
                    [
                        'step'      => 'ESCROW_NOT_FOUND',
                        'reference' => $reference
                    ]
                );

                $reply->text(
                    $message['phone'],
                    "❌ Escrow transaction not found."
                );

                return;

            }

            /*
            |--------------------------------------------------------------------------
            | Verify Seller
            |--------------------------------------------------------------------------
            */

            if (
                (int)$escrow['seller_id']
                !==
                (int)$user['id']
            ) {

                Logger::write(
                    'send_item_handler',
                    [
                        'step'      => 'INVALID_SELLER',
                        'seller_id' => $user['id'],
                        'expected'  => $escrow['seller_id']
                    ]
                );

                $reply->text(
                    $message['phone'],
                    "❌ Only the seller can send this command."
                );

                return;

            }

            /*
            |--------------------------------------------------------------------------
            | Escrow must already be paid
            |--------------------------------------------------------------------------
            */

            if (
                ($escrow['status'] ?? '') !== 'paid'
            ) {

                Logger::write(
                    'send_item_handler',
                    [
                        'step'   => 'NOT_PAID',
                        'status' => $escrow['status'] ?? null
                    ]
                );

                $reply->text(
                    $message['phone'],
                    "❌ Buyer has not completed payment yet."
                );

                return;

            }
                        /*
            |--------------------------------------------------------------------------
            | Prevent Duplicate Shipment
            |--------------------------------------------------------------------------
            */

            if (

                !empty($escrow['seller_confirmed_at'])

                ||

                ($escrow['status'] ?? '') === 'item_sent'

            ) {

                Logger::write(

                    'send_item_handler',

                    [

                        'step'      => 'ALREADY_MARKED_SENT',

                        'reference' => $reference

                    ]

                );

                $reply->text(

                    $message['phone'],

                    "ℹ️ This item has already been marked as sent."

                );

                return;

            }

            /*
            |--------------------------------------------------------------------------
            | Mark Item As Sent
            |--------------------------------------------------------------------------
            */

            if (

                !$this->escrow->sellerConfirm(

                    (int)$escrow['id']

                )

            ) {

                Logger::write(

                    'send_item_handler',

                    [

                        'step'      => 'SELLER_CONFIRM_FAILED',

                        'reference' => $reference

                    ]

                );

                $reply->text(

                    $message['phone'],

                    "❌ Unable to update escrow."

                );

                return;

            }

            Logger::write(

                'send_item_handler',

                [

                    'step'      => 'ITEM_MARKED_SENT',

                    'reference' => $reference

                ]

            );

            /*
            |--------------------------------------------------------------------------
            | Queue Buyer Notification
            |--------------------------------------------------------------------------
            */

            $notificationReference = $reference . '_ITEM_SENT';

            if (

                !$this->notification->exists(

                    (int)$escrow['buyer_id'],

                    $notificationReference

                )

            ) {

                Logger::write(

                    'send_item_handler',

                    [

                        'step'      => 'QUEUE_BUYER_NOTIFICATION',

                        'buyer_id'  => $escrow['buyer_id'],

                        'reference' => $reference

                    ]

                );

                $queued = $this->notification->create(

                    (int)$escrow['buyer_id'],

                    'escrow_item_sent',

                    "Your seller has marked this order as shipped.\n\n".

                    "Escrow Reference:\n".

                    "{$reference}\n\n".

                    "Once you receive and inspect the item, reply:\n\n".

                    "RECEIVED {$reference}\n\n".

                    "Only confirm after you are satisfied with your purchase.",

                    $notificationReference

                );

                Logger::write(

                    'send_item_handler',

                    [

                        'step'      => 'BUYER_NOTIFICATION_RESULT',

                        'reference' => $reference,

                        'queued'    => $queued

                    ]

                );

            } else {

                Logger::write(

                    'send_item_handler',

                    [

                        'step'      => 'BUYER_NOTIFICATION_ALREADY_EXISTS',

                        'reference' => $notificationReference

                    ]

                );

            }

            /*
            |--------------------------------------------------------------------------
            | Continue Buyer Workflow
            |--------------------------------------------------------------------------
            */

            $conversation = new \Models\Conversation();

            $conversation->start(

                (int)$escrow['buyer_id'],

                'Escrow',

                'BUYER_WAIT_DELIVERY',

                'BUYER_WAIT_DELIVERY'

            );

            Logger::write(

                'send_item_handler',

                [

                    'step'      => 'BUYER_WORKFLOW_STARTED',

                    'buyer_id'  => $escrow['buyer_id'],

                    'reference' => $reference

                ]

            );
                        /*
            |--------------------------------------------------------------------------
            | Complete
            |--------------------------------------------------------------------------
            */

            Logger::write(

                'send_item_handler',

                [

                    'step'      => 'COMPLETE',

                    'reference' => $reference

                ]

            );

            /*
            |--------------------------------------------------------------------------
            | Reply Seller
            |--------------------------------------------------------------------------
            */

            $reply->text(

                $message['phone'],

                "✅ Item marked as sent.\n\n".

                "📦 Escrow Reference:\n".

                "{$reference}\n\n".

                "The buyer has been notified.\n\n".

                "Payment will remain securely held until the buyer confirms delivery."

            );

        }

        catch (Throwable $e) {

            Logger::write(

                'send_item_handler_error',

                [

                    'step'    => 'EXCEPTION',

                    'message' => $e->getMessage(),

                    'file'    => $e->getFile(),

                    'line'    => $e->getLine(),

                    'trace'   => $e->getTraceAsString()

                ]

            );

            $reply->text(

                $message['phone'],

                "❌ Unable to process your shipment request.\n\nPlease try again later."

            );

        }

    }

}