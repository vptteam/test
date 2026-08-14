<?php

declare(strict_types=1);

namespace Modules\Escrow\Handlers;

use Core\Logger;
use Core\ReplyFactory;
use Modules\Escrow\Models\Escrow;
use Models\BotNotification;
use Models\Conversation;
use Models\User;
use Throwable;

class ReceivedHandler
{

    /**
     * ---------------------------------------------------------
     * Buyer confirms delivery
     *
     * Command:
     *
     * RECEIVED ESCXXXXXXXX
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
                'escrow_received',
                [
                    'step'    => 'START',
                    'user_id' => $user['id'] ?? null,
                    'phone'   => $message['phone'] ?? null,
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
                'escrow_received',
                [
                    'step'  => 'COMMAND_PARSED',
                    'parts' => $parts
                ]
            );

            if (count($parts) < 2) {

                Logger::write(
                    'escrow_received',
                    [
                        'step' => 'REFERENCE_MISSING'
                    ]
                );

                $this->sendReply(

                    $reply,

                    $message,

                    "✅ CONFIRM DELIVERY\n\n".
                    "Reply using:\n\n".
                    "RECEIVED ESC_REFERENCE"

                );

                return;

            }

            $reference = strtoupper(
                trim($parts[1])
            );

            Logger::write(
                'escrow_received',
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

            $escrowModel = new Escrow();

            $escrow = $escrowModel->findByReference(
                $reference
            );

            Logger::write(
                'escrow_received',
                [
                    'step'      => 'ESCROW_LOADED',
                    'reference' => $reference,
                    'escrow'    => $escrow
                ]
            );

            if (!$escrow) {

                Logger::write(
                    'escrow_received',
                    [
                        'step'      => 'ESCROW_NOT_FOUND',
                        'reference' => $reference
                    ]
                );

                $this->sendReply(

                    $reply,

                    $message,

                    "❌ Escrow transaction not found."

                );

                return;

            }

            /*
            |--------------------------------------------------------------------------
            | Verify Buyer
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'escrow_received',
                [
                    'step' => 'VERIFY_BUYER',
                    'logged_in_user' => $user['id'] ?? null,
                    'expected_buyer' => $escrow['buyer_id'] ?? null,
                    'seller_id'      => $escrow['seller_id'] ?? null
                ]
            );

            if (
                (int)$escrow['buyer_id']
                !==
                (int)$user['id']
            ) {

                Logger::write(
                    'escrow_received',
                    [
                        'step' => 'BUYER_VERIFICATION_FAILED',
                        'logged_in_user' => $user['id'] ?? null,
                        'expected_buyer' => $escrow['buyer_id']
                    ]
                );

                $this->sendReply(

                    $reply,

                    $message,

                    "❌ Only the buyer can confirm delivery."

                );

                return;

            }

            Logger::write(
                'escrow_received',
                [
                    'step' => 'BUYER_VERIFIED'
                ]
            );
                        /*
            |--------------------------------------------------------------------------
            | Validate Escrow Status
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'escrow_received',
                [
                    'step'   => 'STATUS_CHECK',
                    'status' => $escrow['status'] ?? null
                ]
            );

            $status = $escrow['status'] ?? '';

            /*
            |--------------------------------------------------------------------------
            | Seller has not shipped yet
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
                    'escrow_received',
                    [
                        'step'   => 'STATUS_NOT_READY',
                        'status' => $status
                    ]
                );

                $this->sendReply(

                    $reply,

                    $message,

                    "❌ The seller has not marked this item as shipped yet."

                );

                return;

            }

            /*
            |--------------------------------------------------------------------------
            | Already Completed
            |--------------------------------------------------------------------------
            */

            if ($status === 'completed') {

                Logger::write(
                    'escrow_received',
                    [
                        'step'      => 'ALREADY_COMPLETED',
                        'reference' => $reference
                    ]
                );

                $this->sendReply(

                    $reply,

                    $message,

                    "✅ This escrow has already been completed."

                );

                return;

            }

            /*
            |--------------------------------------------------------------------------
            | Already Awaiting Payout
            |--------------------------------------------------------------------------
            */

            if (in_array($status, ['buyer_confirmed', 'awaiting_payout'], true)) {

                Logger::write(
                    'escrow_received',
                    [
                        'step'      => 'ALREADY_AWAITING_PAYOUT',
                        'reference' => $reference
                    ]
                );

                $this->sendReply(

                    $reply,

                    $message,

                    "ℹ️ Your delivery confirmation has already been received."

                );

                return;

            }

            /*
            |--------------------------------------------------------------------------
            | Cancelled
            |--------------------------------------------------------------------------
            */

            if ($status === 'cancelled') {

                Logger::write(
                    'escrow_received',
                    [
                        'step'      => 'ESCROW_CANCELLED',
                        'reference' => $reference
                    ]
                );

                $this->sendReply(

                    $reply,

                    $message,

                    "❌ This escrow has already been cancelled."

                );

                return;

            }

            Logger::write(
                'escrow_received',
                [
                    'step'      => 'STATUS_VALID',
                    'reference' => $reference,
                    'status'    => $status
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Buyer Confirmation
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'escrow_received',
                [
                    'step'      => 'BUYER_CONFIRM_START',
                    'escrow_id' => $escrow['id']
                ]
            );

            $confirmed = $escrowModel->buyerConfirm(
                (int)$escrow['id']
            );

            Logger::write(
                'escrow_received',
                [
                    'step'      => 'BUYER_CONFIRM_RESULT',
                    'result'    => $confirmed,
                    'escrow_id' => $escrow['id']
                ]
            );

            if (!$confirmed) {

                $this->sendReply(

                    $reply,

                    $message,

                    "❌ Unable to confirm delivery."

                );

                return;

            }

            /*
            |--------------------------------------------------------------------------
            | Reload Escrow
            |--------------------------------------------------------------------------
            */

            $escrow = $escrowModel->findByReference(
                $reference
            );

            Logger::write(
                'escrow_received',
                [
                    'step'   => 'ESCROW_RELOADED',
                    'escrow' => $escrow
                ]
            );
                        /*
            |--------------------------------------------------------------------------
            | Finish Buyer Workflow
            |--------------------------------------------------------------------------
            */

            try {

                Logger::write(
                    'escrow_received',
                    [
                        'step' => 'FINISH_BUYER_WORKFLOW_START'
                    ]
                );

                $conversation = new Conversation();

                $buyerConversation = $conversation->active(
                    (int)$escrow['buyer_id']
                );

                Logger::write(
                    'escrow_received',
                    [
                        'step' => 'BUYER_ACTIVE_WORKFLOW',
                        'conversation' => $buyerConversation
                    ]
                );

                if ($buyerConversation) {

                    $conversation->finish(
                        (int)$buyerConversation['id']
                    );

                    Logger::write(
                        'escrow_received',
                        [
                            'step' => 'BUYER_WORKFLOW_FINISHED',
                            'conversation_id' => $buyerConversation['id']
                        ]
                    );

                } else {

                    Logger::write(
                        'escrow_received',
                        [
                            'step' => 'NO_ACTIVE_BUYER_WORKFLOW'
                        ]
                    );

                }

            } catch (Throwable $e) {

                Logger::write(
                    'escrow_received_error',
                    [
                        'step' => 'BUYER_WORKFLOW_EXCEPTION',
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]
                );

            }

            /*
            |--------------------------------------------------------------------------
            | Start Seller BANK Workflow
            |--------------------------------------------------------------------------
            */

            try {

                Logger::write(
                    'escrow_received',
                    [
                        'step' => 'START_BANK_WORKFLOW'
                    ]
                );

                $conversation = new Conversation();

                /*
                |--------------------------------------------------------------
                | Remove any previous active workflow
                |--------------------------------------------------------------
                */

                $sellerConversation = $conversation->active(
                    (int)$escrow['seller_id']
                );

                Logger::write(
                    'escrow_received',
                    [
                        'step' => 'SELLER_ACTIVE_WORKFLOW',
                        'conversation' => $sellerConversation
                    ]
                );

                if ($sellerConversation) {

                    $conversation->finish(
                        (int)$sellerConversation['id']
                    );

                    Logger::write(
                        'escrow_received',
                        [
                            'step' => 'SELLER_PREVIOUS_WORKFLOW_FINISHED',
                            'conversation_id' => $sellerConversation['id']
                        ]
                    );

                }

                /*
                |--------------------------------------------------------------
                | Start BANK workflow
                |--------------------------------------------------------------
                */

                $conversation->start(

                    (int)$escrow['seller_id'],

                    'Escrow',

                    'BANK',

                    'BANK'

                );

                Logger::write(
                    'escrow_received',
                    [
                        'step' => 'BANK_WORKFLOW_STARTED',
                        'seller_id' => $escrow['seller_id']
                    ]
                );

            } catch (Throwable $e) {

                Logger::write(
                    'escrow_received_error',
                    [
                        'step' => 'BANK_WORKFLOW_FAILED',
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]
                );

            }

            /*
            |--------------------------------------------------------------------------
            | Notification Service
            |--------------------------------------------------------------------------
            */

            $bot = new BotNotification();

            Logger::write(
                'escrow_received',
                [
                    'step' => 'BOT_NOTIFICATION_READY'
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Notify Seller (Database + Instant Message)
            |--------------------------------------------------------------------------
            */

            $this->notifySeller(

                $bot,

                $escrow,

                $reference

            );

            Logger::write(
                'escrow_received',
                [
                    'step' => 'SELLER_NOTIFICATION_COMPLETED'
                ]
            );
                        /*
            |--------------------------------------------------------------------------
            | Notify Buyer (Database + Instant Message)
            |--------------------------------------------------------------------------
            */

            $this->notifyBuyer(

                $bot,

                $escrow,

                $reference

            );

            Logger::write(

                'escrow_received',

                [

                    'step' => 'BUYER_NOTIFICATION_COMPLETED'

                ]

            );

            /*
            |--------------------------------------------------------------------------
            | Notify Admin (Database + Instant Message)
            |--------------------------------------------------------------------------
            */

            $adminId = defined('ESCROW_ADMIN_ID')

                ? (int) ESCROW_ADMIN_ID

                : 1;

            $this->notifyAdmin(

                $bot,

                $adminId,

                $reference

            );

            Logger::write(

                'escrow_received',

                [

                    'step' => 'ADMIN_NOTIFICATION_COMPLETED',

                    'admin_id' => $adminId

                ]

            );

            Logger::write(

                'escrow_received',

                [

                    'step'      => 'ALL_NOTIFICATIONS_COMPLETED',

                    'reference' => $reference

                ]

            );

            /*
            |--------------------------------------------------------------------------
            | Reply Buyer
            |--------------------------------------------------------------------------
            */

            $this->sendReply(

                $reply,

                $message,

                "✅ Delivery confirmed successfully.\n\n".

                "📦 Escrow Reference\n".

                "{$reference}\n\n".

                "Your confirmation has been recorded.\n\n".

                "The seller has now been asked to register a payout bank account.\n\n".

                "Once the seller registers a verified bank account, SENDAM Admin will review the transaction and release payment.\n\n".

                "Thank you for using SENDAM Escrow."

            );

            Logger::write(

                'escrow_received',

                [

                    'step'      => 'BUYER_REPLY_SENT',

                    'reference' => $reference

                ]

            );

            Logger::write(

                'escrow_received',

                [

                    'step'      => 'COMPLETE',

                    'reference' => $reference

                ]

            );

        }

        catch (Throwable $e) {

            Logger::write(

                'escrow_received_error',

                [

                    'step'    => 'HANDLER_EXCEPTION',

                    'message' => $e->getMessage(),

                    'file'    => $e->getFile(),

                    'line'    => $e->getLine(),

                    'trace'   => $e->getTraceAsString()

                ]

            );

            $this->sendReply(

                $reply,

                $message,

                "❌ Unable to confirm delivery at this time.\n\nPlease try again later."

            );

        }

    }
        /**
     * ---------------------------------------------------------
     * Notify Seller
     * ---------------------------------------------------------
     */
    private function notifySeller(

        BotNotification $bot,

        array $escrow,

        string $reference

    ): void {

        try {

            Logger::write(
                'escrow_received',
                [
                    'step' => 'NOTIFY_SELLER_START',
                    'seller_id' => $escrow['seller_id']
                ]
            );

            $notificationReference =
                $reference . '_BUYER_CONFIRMED';

            if (
                !$bot->exists(
                    (int)$escrow['seller_id'],
                    'escrow_buyer_confirmed',
                    $notificationReference
                )
            ) {

                $bot->create(

                    (int)$escrow['seller_id'],

                    'escrow_buyer_confirmed',

                    'Buyer Confirmed Delivery',

                    "The buyer has confirmed receiving your item.\n\n".
                    "Escrow Reference:\n".
                    "{$reference}\n\n".
                    "Before payment can be released, you must register a payout bank account.\n\n".
                    "Reply:\n".
                    "BANK BANK CODE YOUR ACCOUNT NUMBER\n\n".
                    "or\n\n".
                   
                    "To see BANKS CODE reply:\n".
                    "BANKS",

                    $notificationReference

                );

                Logger::write(
                    'escrow_received',
                    [
                        'step' => 'SELLER_NOTIFICATION_CREATED'
                    ]
                );

            }

            $userModel = new \Models\User();

            $seller = $userModel->find(
                (int)$escrow['seller_id']
            );

            Logger::write(
                'escrow_received',
                [
                    'step' => 'SELLER_RECORD',
                    'seller' => $seller
                ]
            );

            if ($seller) {

                $sellerReply =
                    \Core\ReplyFactory::make(
                        $seller['platform']
                    );

                $sellerReply->text(

                    $seller['platform_id'],

                    "🎉 Buyer confirmed delivery.\n\n".
                    "Escrow Reference:\n".
                    "{$reference}\n\n".
                    "Reply with:\n".
                    "BANKS\n\n".
                    "TO\n\n".
                    "Get your bank code first"

                );

                Logger::write(
                    'escrow_received',
                    [
                        'step' => 'SELLER_MESSAGE_SENT'
                    ]
                );

            }

        }

        catch(Throwable $e){

            Logger::write(

                'escrow_received_error',

                [

                    'step' => 'SELLER_NOTIFICATION_FAILED',

                    'message' => $e->getMessage(),

                    'file' => $e->getFile(),

                    'line' => $e->getLine()

                ]

            );

        }

    }

    /**
     * ---------------------------------------------------------
     * Notify Buyer
     * ---------------------------------------------------------
     */
    private function notifyBuyer(

        BotNotification $bot,

        array $escrow,

        string $reference

    ): void {

        try {

            Logger::write(
                'escrow_received',
                [
                    'step' => 'NOTIFY_BUYER_START',
                    'buyer_id' => $escrow['buyer_id']
                ]
            );

            $notificationReference =
                $reference . '_DELIVERY_CONFIRMED';

            if (
                !$bot->exists(
                    (int)$escrow['buyer_id'],
                    'escrow_delivery_confirmed',
                    $notificationReference
                )
            ) {

                $bot->create(

                    (int)$escrow['buyer_id'],

                    'escrow_delivery_confirmed',

                    'Delivery Confirmed',

                    "✅ Thank you.\n\n".
                    "Your delivery confirmation has been received.\n\n".
                    "Escrow Reference:\n".
                    "{$reference}\n\n".
                    "SENDAM Admin will now review this transaction before releasing payment.",

                    $notificationReference

                );

                Logger::write(
                    'escrow_received',
                    [
                        'step' => 'BUYER_NOTIFICATION_CREATED'
                    ]
                );

            }

            $userModel = new \Models\User();

            $buyer = $userModel->find(
                (int)$escrow['buyer_id']
            );

            Logger::write(
                'escrow_received',
                [
                    'step' => 'BUYER_RECORD',
                    'buyer' => $buyer
                ]
            );

            if ($buyer) {

                $buyerReply =
                    \Core\ReplyFactory::make(
                        $buyer['platform']
                    );

                $buyerReply->text(

                    $buyer['platform_id'],

                    "✅ Your delivery confirmation has been recorded.\n\n".
                    "Escrow Reference:\n".
                    "{$reference}\n\n".
                    "The seller has been instructed to register a payout bank account.\n\n".
                    "SENDAM Admin will review the transaction afterwards."

                );

                Logger::write(
                    'escrow_received',
                    [
                        'step' => 'BUYER_MESSAGE_SENT'
                    ]
                );

            }

        }

        catch(Throwable $e){

            Logger::write(

                'escrow_received_error',

                [

                    'step' => 'BUYER_NOTIFICATION_FAILED',

                    'message' => $e->getMessage(),

                    'file' => $e->getFile(),

                    'line' => $e->getLine()

                ]

            );

        }

    }
        /**
     * ---------------------------------------------------------
     * Notify Admin
     * ---------------------------------------------------------
     */
    private function notifyAdmin(

        BotNotification $bot,

        int $adminId,

        string $reference

    ): void {

        try {

            Logger::write(
                'escrow_received',
                [
                    'step' => 'NOTIFY_ADMIN_START',
                    'admin_id' => $adminId,
                    'reference' => $reference
                ]
            );


            $notificationReference =
                $reference . '_ADMIN_REVIEW';


            if (
                !$bot->exists(

                    $adminId,

                    'escrow_admin_review',

                    $notificationReference

                )
            ) {


                $bot->create(

                    $adminId,

                    'escrow_admin_review',

                    'Escrow Awaiting Review',

                    "🛡 Buyer has confirmed delivery.\n\n".
                    "📦 Escrow Reference:\n".
                    "{$reference}\n\n".
                    "Status:\n".
                    "Awaiting payout review.\n\n".
                    "Seller has been instructed to register a payout bank account before release.",

                    $notificationReference

                );


                Logger::write(
                    'escrow_received',
                    [
                        'step' => 'ADMIN_NOTIFICATION_CREATED'
                    ]
                );

            }



            /*
            |--------------------------------------------------------------------------
            | Send Immediate Admin Message
            |--------------------------------------------------------------------------
            */


            $userModel = new \Models\User();


            $admin = $userModel->find(
                $adminId
            );


            Logger::write(
                'escrow_received',
                [
                    'step' => 'ADMIN_RECORD',
                    'admin' => $admin
                ]
            );


            if ($admin) {


                $adminReply =
                    \Core\ReplyFactory::make(
                        $admin['platform']
                    );


                $adminReply->text(

                    $admin['platform_id'],

                    "🛡 Escrow Review Required\n\n".
                    "Reference:\n".
                    "{$reference}\n\n".
                    "Buyer has confirmed delivery.\n\n".
                    "Seller payout bank verification is required before release."

                );


                Logger::write(
                    'escrow_received',
                    [
                        'step' => 'ADMIN_MESSAGE_SENT'
                    ]
                );


            }


        }

        catch(Throwable $e) {


            Logger::write(

                'escrow_received_error',

                [

                    'step' => 'ADMIN_NOTIFICATION_FAILED',

                    'message' => $e->getMessage(),

                    'file' => $e->getFile(),

                    'line' => $e->getLine()

                ]

            );


        }

    }



    /**
     * ---------------------------------------------------------
     * Safe Reply Helper
     * ---------------------------------------------------------
     */
    private function sendReply(

        $reply,

        array $message,

        string $text

    ): void {


        try {


            if (
                !empty($message['phone'])
            ) {


                $reply->text(

                    $message['phone'],

                    $text

                );


                Logger::write(

                    'escrow_received',

                    [

                        'step' => 'REPLY_SENT',

                        'phone' => $message['phone']

                    ]

                );


            }


        }

        catch(Throwable $e) {


            Logger::write(

                'escrow_received_error',

                [

                    'step' => 'REPLY_FAILED',

                    'message' => $e->getMessage()

                ]

            );


        }


    }


}