<?php

declare(strict_types=1);

namespace Modules\Escrow\Handlers;

use Core\Logger;
use Modules\Escrow\Models\Escrow;
use Models\BotNotification;
use Services\Escrow\EscrowWalletService;
use Services\Escrow\EscrowSettings;
use Throwable;


class AdminEscrowHandler
{

    protected Escrow $escrowModel;

    protected BotNotification $notification;

    protected EscrowWalletService $walletService;


    public function __construct()
    {

        $this->escrowModel = new Escrow();

        $this->notification = new BotNotification();

        $this->walletService = new EscrowWalletService();

    }



    /**
     * ---------------------------------------------------------
     * WorkflowExecutor ENTRY
     *
     * Commands:
     *
     * APPROVE ESCXXXX
     * PAY ESCXXXX
     * REFUND ESCXXXX
     * HOLD ESCXXXX
     * DISPUTE ESCXXXX
     *
     * ---------------------------------------------------------
     */
    public function execute(

        $reply,

        array $user,

        array $message,

        string $text,

        array $data = []

    ): bool {

        $this->start(

            $reply,

            $user,

            $message,

            $text

        );


        return true;

    }



    /**
     * ---------------------------------------------------------
     * ADMIN ESCROW COMMAND PROCESSOR
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

                'escrow_admin',

                [

                    'step'     => 'START',

                    'admin_id' => $user['id'] ?? null,

                    'command'  => $text

                ]

            );



            /*
            |--------------------------------------------------------------------------
            | CHECK ADMIN PERMISSION
            |--------------------------------------------------------------------------
            */

            $role = $user['role'] ?? '';

            if (

                !in_array(

                    $role,

                    [

                        'admin',

                        'super_admin'

                    ],

                    true

                )

            ) {


                Logger::write(

                    'escrow_admin',

                    [

                        'step' => 'UNAUTHORIZED',

                        'user_id' => $user['id'] ?? null,

                        'role' => $role

                    ]

                );


                $reply->text(

                    $message['phone'],

                    "❌ Unauthorized."

                );


                return;

            }




            /*
            |--------------------------------------------------------------------------
            | PARSE COMMAND
            |--------------------------------------------------------------------------
            */


            $parts = preg_split(

                '/\s+/',

                trim($text)

            );



            if (

                count($parts) < 2

            ) {


                $reply->text(

                    $message['phone'],

                    "🛡 *ESCROW ADMIN COMMANDS*\n\n".

                    "APPROVE ESCXXXXXXXX\n".

                    "PAY ESCXXXXXXXX\n".

                    "REFUND ESCXXXXXXXX\n".

                    "HOLD ESCXXXXXXXX\n".

                    "DISPUTE ESCXXXXXXXX"

                );


                return;

            }



            $action = strtoupper(

                trim($parts[0])

            );


            $reference = strtoupper(

                trim($parts[1])

            );



            Logger::write(

                'escrow_admin',

                [

                    'step'      => 'COMMAND_PARSED',

                    'action'    => $action,

                    'reference' => $reference

                ]

            );




            /*
            |--------------------------------------------------------------------------
            | LOAD ESCROW
            |--------------------------------------------------------------------------
            */


            $escrow = $this->escrowModel->findByReference(

                $reference

            );



            if (!$escrow) {


                $reply->text(

                    $message['phone'],

                    "❌ Escrow transaction not found."

                );


                return;

            }



            Logger::write(

                'escrow_admin',

                [

                    'step'      => 'ESCROW_FOUND',

                    'escrow_id' => $escrow['id'],

                    'status'    => $escrow['status']

                ]

            );




            /*
            |--------------------------------------------------------------------------
            | ROUTE COMMAND
            |--------------------------------------------------------------------------
            */


            switch ($action) {


                case 'APPROVE':


                    $this->approve(

                        $reply,

                        $message,

                        $escrow

                    );


                    break;



                case 'PAY':


                    $this->pay(

                        $reply,

                        $message,

                        $escrow

                    );


                    break;



                case 'REFUND':


                    $this->refund(

                        $reply,

                        $message,

                        $escrow

                    );


                    break;



                case 'HOLD':


                    $this->hold(

                        $reply,

                        $message,

                        $escrow

                    );


                    break;



                case 'DISPUTE':


                    $this->dispute(

                        $reply,

                        $message,

                        $escrow

                    );


                    break;



                default:


                    $reply->text(

                        $message['phone'],

                        "❌ Unknown escrow admin command."

                    );


                    break;


            }



        }

        catch(Throwable $e) {


            Logger::write(

                'escrow_admin_error',

                [

                    'message' => $e->getMessage(),

                    'file'    => $e->getFile(),

                    'line'    => $e->getLine()

                ]

            );



            $reply->text(

                $message['phone'],

                "❌ Unable to process escrow command."

            );


        }

    }
        /**
     * ---------------------------------------------------------
     * APPROVE ESCROW
     *
     * Flow:
     *
     * Admin approves completed escrow
     *
     * If AUTO_PAYOUT = true
     *      -> Pay seller immediately
     *
     * If AUTO_PAYOUT = false
     *      -> Mark approved
     *      -> Wait for PAY command
     *
     * ---------------------------------------------------------
     */
    private function approve(

        $reply,

        array $message,

        array $escrow

    ): void {

        try {


            Logger::write(

                'escrow_admin',

                [

                    'step'      => 'APPROVE_START',

                    'reference' => $escrow['reference']

                ]

            );



            /*
            |--------------------------------------------------------------------------
            | Validate Status
            |--------------------------------------------------------------------------
            */

            if (

                !in_array(

                    $escrow['status'],

                    [

                        'awaiting_payout',

                        'received',

                        'completed'

                    ],

                    true

                )

            ) {


                $reply->text(

                    $message['phone'],

                    "❌ Escrow is not ready for approval.\n\n".

                    "Current status: ".$escrow['status']

                );


                return;

            }




            /*
            |--------------------------------------------------------------------------
            | Check Seller Bank
            |--------------------------------------------------------------------------
            */


            if (

                !$this->walletService->walletReady(

                    (int)$escrow['seller_id']

                )

            ) {


                $reply->text(

                    $message['phone'],

                    "❌ Seller payout account is not ready."

                );


                return;

            }




            /*
            |--------------------------------------------------------------------------
            | Calculate Seller Amount
            |--------------------------------------------------------------------------
            */


            $amount = (float)(

                $escrow['seller_amount']

                ??

                $escrow['amount']

                ??

                0

            );



            if ($amount <= 0) {


                $reply->text(

                    $message['phone'],

                    "❌ Invalid escrow amount."

                );


                return;

            }





            /*
            |--------------------------------------------------------------------------
            | AUTO PAYOUT
            |--------------------------------------------------------------------------
            */


            if (

                EscrowSettings::AUTO_PAYOUT

            ) {


                Logger::write(

                    'escrow_admin',

                    [

                        'step' => 'AUTO_PAYOUT',

                        'reference' => $escrow['reference'],

                        'amount' => $amount

                    ]

                );



                $transfer = $this->walletService->payout(

                    (int)$escrow['seller_id'],

                    $amount,

                    $escrow['reference']

                );



                if (

                    !($transfer['success'] ?? false)

                ) {


                    Logger::write(

                        'escrow_admin',

                        [

                            'step' => 'PAYOUT_FAILED',

                            'response' => $transfer

                        ]

                    );



                    $reply->text(

                        $message['phone'],

                        "❌ Payout failed.\n\n".

                        ($transfer['message'] ?? 'Unknown error')

                    );


                    return;

                }




                /*
                |--------------------------------------------------------------------------
                | Save Transfer Details
                |--------------------------------------------------------------------------
                */


                $this->escrowModel->update(

                    (int)$escrow['id'],

                    [

                        'status' => 'paid',

                        'transfer_code' =>

                            $transfer['data']['transfer_code'] ?? null,


                        'payment_reference' =>

                            $transfer['data']['reference'] ?? null,


                        'gateway_status' =>

                            $transfer['data']['status'] ?? null,


                        'paid_at' => date(

                            'Y-m-d H:i:s'

                        )

                    ]

                );



                $this->sendCompletedNotifications(

                    $escrow

                );



                $reply->text(

                    $message['phone'],

                    "✅ Escrow approved.\n\n".

                    "Seller payout completed successfully.\n\n".

                    "Reference:\n".

                    $escrow['reference']

                );


                return;


            }




            /*
            |--------------------------------------------------------------------------
            | MANUAL PAYOUT MODE
            |--------------------------------------------------------------------------
            */


            $this->escrowModel->update(

                (int)$escrow['id'],

                [

                    'status' => 'approved'

                ]

            );



            $this->notify(

                (int)$escrow['seller_id'],

                'escrow_approved',

                'Escrow Approved',

                "✅ Your escrow has been approved.\n\n".

                "Reference:\n".

                $escrow['reference']."\n\n".

                "Your payout will be processed shortly.",

                $escrow['reference'].'_APPROVED_SELLER'

            );



            $this->notify(

                (int)$escrow['buyer_id'],

                'escrow_approved',

                'Escrow Approved',

                "✅ SENDAM has approved this escrow.\n\n".

                "Reference:\n".

                $escrow['reference'],

                $escrow['reference'].'_APPROVED_BUYER'

            );



            $reply->text(

                $message['phone'],

                "✅ Escrow approved.\n\n".

                "Automatic payout is disabled.\n\n".

                "Use:\n".

                "PAY ".$escrow['reference']

            );



        }

        catch(Throwable $e) {


            Logger::write(

                'escrow_admin_approve_error',

                [

                    'message'=>$e->getMessage(),

                    'line'=>$e->getLine()

                ]

            );


            $reply->text(

                $message['phone'],

                "❌ Unable to approve escrow."

            );


        }

    }
        /**
     * ---------------------------------------------------------
     * PAY ESCROW
     *
     * Manual payout command:
     *
     * PAY ESCXXXXXXXX
     *
     * Used when AUTO_PAYOUT is disabled.
     *
     * ---------------------------------------------------------
     */
    private function pay(

        $reply,

        array $message,

        array $escrow

    ): void {

        try {


            Logger::write(

                'escrow_admin',

                [

                    'step'      => 'PAY_START',

                    'reference' => $escrow['reference']

                ]

            );



            /*
            |--------------------------------------------------------------------------
            | Check Manual Payout Setting
            |--------------------------------------------------------------------------
            */


            if (

                !EscrowSettings::MANUAL_PAYOUT

            ) {


                $reply->text(

                    $message['phone'],

                    "❌ Manual payout is disabled."

                );


                return;

            }




            /*
            |--------------------------------------------------------------------------
            | Escrow Must Be Approved
            |--------------------------------------------------------------------------
            */


            if (

                ($escrow['status'] ?? '')

                !==

                'approved'

            ) {


                $reply->text(

                    $message['phone'],

                    "❌ Only approved escrows can be paid.\n\n".

                    "Current status: ".$escrow['status']

                );


                return;

            }




            $amount = (float)(

                $escrow['seller_amount']

                ??

                $escrow['amount']

                ??

                0

            );



            if ($amount <= 0) {


                $reply->text(

                    $message['phone'],

                    "❌ Invalid payout amount."

                );


                return;

            }




            /*
            |--------------------------------------------------------------------------
            | Process Transfer
            |--------------------------------------------------------------------------
            */


            $transfer = $this->walletService->payout(

                (int)$escrow['seller_id'],

                $amount,

                $escrow['reference']

            );



            if (

                !($transfer['success'] ?? false)

            ) {


                Logger::write(

                    'escrow_admin',

                    [

                        'step'=>'MANUAL_PAY_FAILED',

                        'response'=>$transfer

                    ]

                );



                $reply->text(

                    $message['phone'],

                    "❌ Payment failed.\n\n".

                    ($transfer['message'] ?? 'Unknown error')

                );


                return;

            }




            /*
            |--------------------------------------------------------------------------
            | Release Escrow
            |--------------------------------------------------------------------------
            */


            $this->escrowModel->release(

                (int)$escrow['id']

            );



            $this->escrowModel->update(

                (int)$escrow['id'],

                [

                    'transfer_code' =>

                        $transfer['data']['transfer_code'] ?? null,


                    'gateway_status' =>

                        $transfer['data']['status'] ?? null,


                    'paid_at' => date(

                        'Y-m-d H:i:s'

                    )

                ]

            );




            $this->sendCompletedNotifications(

                $escrow

            );




            Logger::write(

                'escrow_admin',

                [

                    'step'=>'MANUAL_PAY_COMPLETE',

                    'reference'=>$escrow['reference']

                ]

            );



            $reply->text(

                $message['phone'],

                "✅ Manual payout completed.\n\n".

                "Reference:\n".

                $escrow['reference']

            );


        }

        catch(Throwable $e) {


            Logger::write(

                'escrow_admin_pay_error',

                [

                    'message'=>$e->getMessage(),

                    'line'=>$e->getLine()

                ]

            );


            $reply->text(

                $message['phone'],

                "❌ Unable to process payout."

            );


        }

    }




    /**
     * ---------------------------------------------------------
     * REFUND ESCROW
     *
     * REFUND ESCXXXXXXXX
     *
     * ---------------------------------------------------------
     */
    private function refund(

        $reply,

        array $message,

        array $escrow

    ): void {

        try {


            Logger::write(

                'escrow_admin',

                [

                    'step'=>'REFUND_START',

                    'reference'=>$escrow['reference']

                ]

            );



            if (

                in_array(

                    $escrow['status'],

                    [

                        'paid',

                        'refunded'

                    ],

                    true

                )

            ) {


                $reply->text(

                    $message['phone'],

                    "❌ This escrow cannot be refunded."

                );


                return;

            }




            /*
            |--------------------------------------------------------------------------
            | Update Escrow Status
            |--------------------------------------------------------------------------
            */


            $this->escrowModel->update(

                (int)$escrow['id'],

                [

                    'status'=>'refunded',

                    'refunded_at'=>date(

                        'Y-m-d H:i:s'

                    )

                ]

            );




            $this->notify(

                (int)$escrow['buyer_id'],

                'escrow_refunded',

                'Escrow Refund',

                "💰 Your escrow payment has been refunded.\n\n".

                "Reference:\n".

                $escrow['reference'],

                $escrow['reference'].'_REFUND_BUYER'

            );



            $this->notify(

                (int)$escrow['seller_id'],

                'escrow_refunded',

                'Escrow Refunded',

                "⚠️ This escrow transaction was refunded.\n\n".

                "Reference:\n".

                $escrow['reference'],

                $escrow['reference'].'_REFUND_SELLER'

            );




            $reply->text(

                $message['phone'],

                "✅ Escrow refunded successfully.\n\n".

                "Reference:\n".

                $escrow['reference']

            );


        }

        catch(Throwable $e) {


            Logger::write(

                'escrow_admin_refund_error',

                [

                    'message'=>$e->getMessage()

                ]

            );


            $reply->text(

                $message['phone'],

                "❌ Unable to refund escrow."

            );

        }

    }
        /**
     * ---------------------------------------------------------
     * HOLD ESCROW
     *
     * HOLD ESCXXXXXXXX
     *
     * ---------------------------------------------------------
     */
    private function hold(

        $reply,

        array $message,

        array $escrow

    ): void {

        try {


            Logger::write(

                'escrow_admin',

                [

                    'step'=>'HOLD',

                    'reference'=>$escrow['reference']

                ]

            );



            $this->escrowModel->update(

                (int)$escrow['id'],

                [

                    'status'=>'on_hold'

                ]

            );



            $text =

                "⏸ *ESCROW ON HOLD*\n\n".

                "Reference:\n".

                $escrow['reference'].

                "\n\n".

                "SENDAM has placed this transaction on hold while it is being reviewed.";



            $this->notify(

                (int)$escrow['buyer_id'],

                'escrow_hold',

                'Escrow On Hold',

                $text,

                $escrow['reference'].'_HOLD_BUYER'

            );



            $this->notify(

                (int)$escrow['seller_id'],

                'escrow_hold',

                'Escrow On Hold',

                $text,

                $escrow['reference'].'_HOLD_SELLER'

            );



            $reply->text(

                $message['phone'],

                "✅ Escrow placed on hold.\n\n".

                "Reference:\n".

                $escrow['reference']

            );


        }

        catch(Throwable $e) {


            Logger::write(

                'escrow_admin_hold_error',

                [

                    'message'=>$e->getMessage()

                ]

            );


            $reply->text(

                $message['phone'],

                "❌ Unable to place escrow on hold."

            );

        }

    }




    /**
     * ---------------------------------------------------------
     * DISPUTE ESCROW
     *
     * DISPUTE ESCXXXXXXXX
     *
     * ---------------------------------------------------------
     */
    private function dispute(

        $reply,

        array $message,

        array $escrow

    ): void {

        try {


            Logger::write(

                'escrow_admin',

                [

                    'step'=>'DISPUTE',

                    'reference'=>$escrow['reference']

                ]

            );



            $this->escrowModel->update(

                (int)$escrow['id'],

                [

                    'status'=>'disputed'

                ]

            );



            $text =

                "⚖ *ESCROW DISPUTE OPENED*\n\n".

                "Reference:\n".

                $escrow['reference'].

                "\n\n".

                "SENDAM has opened a dispute investigation.\n\n".

                "Both parties may be contacted for additional verification.";




            $this->notify(

                (int)$escrow['buyer_id'],

                'escrow_dispute',

                'Escrow Dispute',

                $text,

                $escrow['reference'].'_DISPUTE_BUYER'

            );



            $this->notify(

                (int)$escrow['seller_id'],

                'escrow_dispute',

                'Escrow Dispute',

                $text,

                $escrow['reference'].'_DISPUTE_SELLER'

            );




            $reply->text(

                $message['phone'],

                "✅ Dispute opened successfully.\n\n".

                "Reference:\n".

                $escrow['reference']

            );


        }

        catch(Throwable $e) {


            Logger::write(

                'escrow_admin_dispute_error',

                [

                    'message'=>$e->getMessage()

                ]

            );


            $reply->text(

                $message['phone'],

                "❌ Unable to open dispute."

            );

        }

    }




    /**
     * ---------------------------------------------------------
     * CREATE BOT NOTIFICATION
     * ---------------------------------------------------------
     */
    private function notify(

        int $userId,

        string $type,

        string $title,

        string $message,

        string $reference

    ): void {


        try {


            if (

                $this->notification->exists(

                    $userId,

                    $reference

                )

            ) {

                return;

            }



            $this->notification->create(

                $userId,

                $type,

                $title,

                $message,

                $reference

            );


        }

        catch(Throwable $e) {


            Logger::write(

                'escrow_admin_notification_error',

                [

                    'message'=>$e->getMessage(),

                    'user_id'=>$userId

                ]

            );

        }

    }




    /**
     * ---------------------------------------------------------
     * BUYER + SELLER COMPLETION NOTICE
     * ---------------------------------------------------------
     */
    private function sendCompletedNotifications(

        array $escrow

    ): void {


        $reference = $escrow['reference'];



        $this->notify(

            (int)$escrow['seller_id'],

            'escrow_paid',

            'Escrow Payment Released',

            "🎉 Your escrow payment has been released successfully.\n\n".

            "Reference:\n".

            $reference,

            $reference.'_PAID'

        );



        $this->notify(

            (int)$escrow['buyer_id'],

            'escrow_completed',

            'Escrow Completed',

            "✅ Your escrow transaction has been completed successfully.\n\n".

            "Reference:\n".

            $reference."\n\n".

            "Seller payment has been released.",

            $reference.'_COMPLETE'

        );

    }


}