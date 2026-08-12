<?php

declare(strict_types=1);

namespace Modules\Escrow\Handlers;

use Core\Logger;
use Modules\Escrow\Models\Escrow;
use Models\BotNotification;
use Models\Conversation;
use Throwable;

class ShipHandler
{
    /**
     * Seller marks an item as shipped.
     *
     * Usage:
     *
     * SHIP ESCXXXXXXXX
     */
    public function start(
        $reply,
        array $user,
        array $message,
        string $text
    ): void {

        try {

            /*
            |--------------------------------------------------------------------------
            | Handler Started
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'escrow_ship',
                [
                    'step'    => 'START',
                    'user'    => $user,
                    'message' => $message,
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

            Logger::write(
                'escrow_ship',
                [
                    'step'  => 'COMMAND_PARSED',
                    'parts' => $parts
                ]
            );

            if (empty($parts[1])) {

                Logger::write(
                    'escrow_ship',
                    [
                        'step' => 'REFERENCE_MISSING'
                    ]
                );

                $reply->text(

                    $message['phone'],

                    "📦 SHIP ITEM\n\n".
                    "Usage:\n".
                    "SHIP ESC_REFERENCE"

                );

                return;

            }

            /*
            |--------------------------------------------------------------------------
            | Escrow Reference
            |--------------------------------------------------------------------------
            */

            $reference = strtoupper(
                trim($parts[1])
            );

            Logger::write(
                'escrow_ship',
                [
                    'step'      => 'REFERENCE_READY',
                    'reference' => $reference
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Load Escrow
            |--------------------------------------------------------------------------
            */

            $escrowModel = new Escrow();

            Logger::write(
                'escrow_ship',
                [
                    'step' => 'ESCROW_MODEL_CREATED'
                ]
            );

            $escrow = $escrowModel->findByReference(
                $reference
            );

            Logger::write(
                'escrow_ship',
                [
                    'step'      => 'ESCROW_LOADED',
                    'reference' => $reference,
                    'escrow'    => $escrow
                ]
            );

            if (!$escrow) {

                Logger::write(
                    'escrow_ship',
                    [
                        'step'      => 'ESCROW_NOT_FOUND',
                        'reference' => $reference
                    ]
                );

                $reply->text(

                    $message['phone'],

                    "❌ Escrow not found."

                );

                return;

            }

            /*
            |--------------------------------------------------------------------------
            | Verify Seller
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'escrow_ship',
                [
                    'step' => 'VERIFY_SELLER',
                    'expected_seller' => (int)$escrow['seller_id'],
                    'current_user'    => (int)($user['id'] ?? 0),
                    'buyer_id'        => (int)$escrow['buyer_id']
                ]
            );

            if (
                (int)$escrow['seller_id']
                !==
                (int)($user['id'] ?? 0)
            ) {

                Logger::write(
                    'escrow_ship',
                    [
                        'step' => 'SELLER_VALIDATION_FAILED',
                        'expected' => (int)$escrow['seller_id'],
                        'actual'   => (int)($user['id'] ?? 0),
                        'user'     => $user
                    ]
                );

                $reply->text(

                    $message['phone'],

                    "❌ Only the seller can mark this order as shipped."

                );

                return;

            }

            Logger::write(
                'escrow_ship',
                [
                    'step' => 'SELLER_VALIDATION_PASSED'
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Validate Escrow Status
            |--------------------------------------------------------------------------
            */

            $status = strtolower(
                trim(
                    (string)($escrow['status'] ?? '')
                )
            );

            Logger::write(
                'escrow_ship',
                [
                    'step'   => 'STATUS_CHECK',
                    'status' => $status
                ]
            );

            if ($status === 'item_sent') {

                Logger::write(
                    'escrow_ship',
                    [
                        'step' => 'ALREADY_SHIPPED'
                    ]
                );

                $reply->text(

                    $message['phone'],

                    "📦 This item has already been marked as shipped."

                );

                return;

            }

            if ($status === 'completed') {

                Logger::write(
                    'escrow_ship',
                    [
                        'step' => 'ESCROW_COMPLETED'
                    ]
                );

                $reply->text(

                    $message['phone'],

                    "✅ This escrow has already been completed."

                );

                return;

            }

            if ($status === 'cancelled') {

                Logger::write(
                    'escrow_ship',
                    [
                        'step' => 'ESCROW_CANCELLED'
                    ]
                );

                $reply->text(

                    $message['phone'],

                    "❌ This escrow has been cancelled."

                );

                return;

            }

            if ($status !== 'paid') {

                Logger::write(
                    'escrow_ship',
                    [
                        'step' => 'INVALID_STATUS',
                        'status' => $status
                    ]
                );

                $reply->text(

                    $message['phone'],

                    "❌ This escrow is not ready for shipment.\n\n".
                    "Current status: {$status}"

                );

                return;

            }

            Logger::write(
                'escrow_ship',
                [
                    'step' => 'STATUS_VALIDATED'
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Continue with seller confirmation...
            |--------------------------------------------------------------------------
            */
			
			            /*
            |--------------------------------------------------------------------------
            | Seller Confirms Shipment
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'escrow_ship',
                [
                    'step'      => 'SELLER_CONFIRM_START',
                    'escrow_id' => (int)$escrow['id'],
                    'reference' => $reference
                ]
            );

            $updated = $escrowModel->sellerConfirm(
                (int)$escrow['id']
            );

            Logger::write(
                'escrow_ship',
                [
                    'step'      => 'SELLER_CONFIRM_RESULT',
                    'updated'   => $updated,
                    'escrow_id' => (int)$escrow['id']
                ]
            );

            if (!$updated) {

                Logger::write(
                    'escrow_ship',
                    [
                        'step'      => 'SELLER_CONFIRM_FAILED',
                        'escrow_id' => (int)$escrow['id']
                    ]
                );

                $reply->text(

                    $message['phone'],

                    "❌ Unable to update shipment status."

                );

                return;

            }

            Logger::write(
                'escrow_ship',
                [
                    'step'      => 'SELLER_CONFIRM_SUCCESS',
                    'escrow_id' => (int)$escrow['id']
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Reload Escrow
            |--------------------------------------------------------------------------
            | Confirm that sellerConfirm() actually updated the database.
            */

            $escrow = $escrowModel->findByReference(
                $reference
            );

            Logger::write(
                'escrow_ship',
                [
                    'step'         => 'ESCROW_RELOADED',
                    'reference'    => $reference,
                    'status'       => $escrow['status'] ?? null,
                    'seller_time'  => $escrow['seller_confirmed_at'] ?? null,
                    'updated_data' => $escrow
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Create Notification Model
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'escrow_ship',
                [
                    'step' => 'BOT_NOTIFICATION_INIT'
                ]
            );

            $notification = new BotNotification();

            $notificationReference =
                $reference . '_SHIPPED';

            Logger::write(
                'escrow_ship',
                [
                    'step' => 'NOTIFICATION_REFERENCE_READY',
                    'notification_reference' => $notificationReference
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Check Existing Notification
            |--------------------------------------------------------------------------
            */

            $exists = $notification->exists(

                (int)$escrow['buyer_id'],

                'escrow_shipped',

                $notificationReference

            );

            Logger::write(
                'escrow_ship',
                [
                    'step' => 'NOTIFICATION_EXISTS_CHECK',
                    'exists' => $exists
                ]
            );

            if (!$exists) {

                Logger::write(
                    'escrow_ship',
                    [
                        'step' => 'CREATING_BUYER_NOTIFICATION'
                    ]
                );
				            /*
            |--------------------------------------------------------------------------
            | Create Buyer Notification
            |--------------------------------------------------------------------------
            */

            $created = $notification->create(

                (int)$escrow['buyer_id'],

                'escrow_shipped',

                'Item Shipped',

                "📦 Your seller has marked the item as shipped.\n\n".
                "Escrow Reference:\n".
                "{$reference}\n\n".
                "Once you receive the item, reply:\n\n".
                "RECEIVED {$reference}",

                $notificationReference

            );

            Logger::write(
                'escrow_ship',
                [
                    'step' => 'BUYER_NOTIFICATION_CREATED',
                    'result' => $created,
                    'buyer_id' => (int)$escrow['buyer_id']
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Load Buyer
            |--------------------------------------------------------------------------
            */

            try {

                Logger::write(
                    'escrow_ship',
                    [
                        'step' => 'LOADING_BUYER'
                    ]
                );

                $userModel = new \Models\User();

                $buyer = $userModel->find(
                    (int)$escrow['buyer_id']
                );

                Logger::write(
                    'escrow_ship',
                    [
                        'step' => 'BUYER_LOADED',
                        'buyer' => $buyer
                    ]
                );

                if (!$buyer) {

                    Logger::write(
                        'escrow_ship',
                        [
                            'step' => 'BUYER_NOT_FOUND'
                        ]
                    );

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Create Reply Driver
                    |--------------------------------------------------------------------------
                    */

                    Logger::write(
                        'escrow_ship',
                        [
                            'step' => 'CREATING_REPLY_DRIVER',
                            'platform' => $buyer['platform'] ?? null
                        ]
                    );

                    $buyerReply = \Core\ReplyFactory::make(
                        $buyer['platform']
                    );

                    Logger::write(
                        'escrow_ship',
                        [
                            'step' => 'REPLY_DRIVER_CREATED'
                        ]
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Send Buyer Message
                    |--------------------------------------------------------------------------
                    */

                    $buyerReply->text(

                        $buyer['platform_id'],

                        "📦 Your seller has marked the item as shipped.\n\n".
                        "Escrow Reference:\n".
                        "{$reference}\n\n".
                        "Once you receive the item, reply:\n\n".
                        "RECEIVED {$reference}"

                    );

                    Logger::write(
                        'escrow_ship',
                        [
                            'step' => 'BUYER_MESSAGE_SENT',
                            'buyer_id' => (int)$buyer['id']
                        ]
                    );

                }

            } catch (Throwable $e) {

                Logger::write(
                    'escrow_ship_error',
                    [
                        'step' => 'BUYER_MESSAGE_FAILED',
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ]
                );

            }

            /*
            |--------------------------------------------------------------------------
            | Notification Already Exists
            |--------------------------------------------------------------------------
            */

            } else {

                Logger::write(
                    'escrow_ship',
                    [
                        'step' => 'BUYER_NOTIFICATION_ALREADY_EXISTS',
                        'buyer_id' => (int)$escrow['buyer_id']
                    ]
                );

            }

            Logger::write(
                'escrow_ship',
                [
                    'step' => 'BUYER_NOTIFICATION_STAGE_COMPLETE'
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Continue to Buyer Workflow...
            |--------------------------------------------------------------------------
            */
			            /*
            |--------------------------------------------------------------------------
            | Buyer Workflow
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'escrow_ship',
                [
                    'step' => 'WORKFLOW_START'
                ]
            );

            $conversation = new Conversation();

            Logger::write(
                'escrow_ship',
                [
                    'step' => 'CONVERSATION_MODEL_CREATED'
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Cancel Existing Workflow
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'escrow_ship',
                [
                    'step'     => 'CANCEL_EXISTING_WORKFLOW',
                    'buyer_id' => (int)$escrow['buyer_id']
                ]
            );

            $cancelled = $conversation->cancel(
                (int)$escrow['buyer_id']
            );

            Logger::write(
                'escrow_ship',
                [
                    'step'      => 'WORKFLOW_CANCEL_RESULT',
                    'buyer_id'  => (int)$escrow['buyer_id'],
                    'cancelled' => $cancelled
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Start Buyer Delivery Workflow
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'escrow_ship',
                [
                    'step'     => 'STARTING_BUYER_WORKFLOW',
                    'buyer_id' => (int)$escrow['buyer_id']
                ]
            );

            $started = $conversation->start(

                (int)$escrow['buyer_id'],

                'Escrow',

                'BUYER_WAIT_DELIVERY',

                'BUYER_WAIT_DELIVERY'

            );

            Logger::write(
                'escrow_ship',
                [
                    'step'     => 'BUYER_WORKFLOW_RESULT',
                    'buyer_id' => (int)$escrow['buyer_id'],
                    'started'  => $started
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Verify Workflow
            |--------------------------------------------------------------------------
            */

            $activeConversation = $conversation->active(
                (int)$escrow['buyer_id']
            );

            Logger::write(
                'escrow_ship',
                [
                    'step'         => 'ACTIVE_WORKFLOW',
                    'conversation' => $activeConversation
                ]
            );

            Logger::write(
                'escrow_ship',
                [
                    'step' => 'WORKFLOW_STAGE_COMPLETE'
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Continue to Seller Reply...
            |--------------------------------------------------------------------------
            */
			
			            /*
            |--------------------------------------------------------------------------
            | Reply Seller
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'escrow_ship',
                [
                    'step'      => 'PREPARING_SELLER_REPLY',
                    'seller_id' => (int)$escrow['seller_id'],
                    'reference' => $reference
                ]
            );

            $sellerMessage =
                "✅ Shipment confirmed.\n\n".
                "Reference:\n".
                "{$reference}\n\n".
                "The buyer has been notified.\n\n".
                "The funds will remain safely in escrow until the buyer confirms delivery.";

            Logger::write(
                'escrow_ship',
                [
                    'step'    => 'SELLER_REPLY_CONTENT',
                    'message' => $sellerMessage
                ]
            );

            $reply->text(

                $message['phone'],

                $sellerMessage

            );

            Logger::write(
                'escrow_ship',
                [
                    'step'      => 'SELLER_REPLY_SENT',
                    'seller_id' => (int)$escrow['seller_id'],
                    'phone'     => $message['phone'] ?? null
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Final Verification
            |--------------------------------------------------------------------------
            */

            $finalEscrow = $escrowModel->findByReference(
                $reference
            );

            Logger::write(
                'escrow_ship',
                [
                    'step'         => 'FINAL_ESCROW_STATE',
                    'reference'    => $reference,
                    'escrow_state' => $finalEscrow
                ]
            );

            Logger::write(
                'escrow_ship',
                [
                    'step'      => 'SHIP_HANDLER_COMPLETED',
                    'reference' => $reference,
                    'seller_id' => (int)$escrow['seller_id'],
                    'buyer_id'  => (int)$escrow['buyer_id'],
                    'status'    => $finalEscrow['status'] ?? null
                ]
            );
			
			        }

        catch (Throwable $e) {

            Logger::write(

                'escrow_ship_error',

                [

                    'step'      => 'EXCEPTION',

                    'message'   => $e->getMessage(),

                    'file'      => $e->getFile(),

                    'line'      => $e->getLine(),

                    'trace'     => $e->getTraceAsString(),

                    'reference' => $reference ?? null,

                    'user_id'   => $user['id'] ?? null,

                    'phone'     => $message['phone'] ?? null,

                    'text'      => $text ?? null

                ]

            );

            try {

                Logger::write(

                    'escrow_ship',

                    [

                        'step' => 'SENDING_ERROR_REPLY'

                    ]

                );

                $reply->text(

                    $message['phone'] ?? '',

                    "❌ Unable to process shipment.\n\nPlease try again later."

                );

                Logger::write(

                    'escrow_ship',

                    [

                        'step' => 'ERROR_REPLY_SENT'

                    ]

                );

            }

            catch (Throwable $replyException) {

                Logger::write(

                    'escrow_ship_error',

                    [

                        'step'    => 'ERROR_REPLY_FAILED',

                        'message' => $replyException->getMessage(),

                        'file'    => $replyException->getFile(),

                        'line'    => $replyException->getLine(),

                        'trace'   => $replyException->getTraceAsString()

                    ]

                );

            }

        }

    }

}