<?php

declare(strict_types=1);

namespace Services\Escrow;

use Core\Logger;
use Throwable;

use Modules\Escrow\Models\Escrow;
use Modules\Escrow\Models\EscrowWallet;
use Modules\Escrow\Models\EscrowPayout;

use Services\Payments\PaystackTransfer;

use Models\BotNotification;

class EscrowPayoutService
{

    protected Escrow $escrow;

    protected EscrowWallet $wallet;

    protected EscrowPayout $payout;

    protected PaystackTransfer $transfer;

    protected BotNotification $notification;


    public function __construct()
    {

        $this->escrow = new Escrow();

        $this->wallet = new EscrowWallet();

        $this->payout = new EscrowPayout();

        $this->transfer = new PaystackTransfer();

        $this->notification = new BotNotification();

    }


    /**
     * ---------------------------------------------------------
     * Release Escrow To Seller
     *
     * Called ONLY by Admin
     * ---------------------------------------------------------
     */
    public function release(
        int $escrowId,
        int $adminId
    ): bool
    {

        try {

            Logger::write(
                'escrow_payout',
                [
                    'step'      => 'START',
                    'escrow_id' => $escrowId,
                    'admin_id'  => $adminId
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Load Escrow
            |--------------------------------------------------------------------------
            */

            $escrow = $this->escrow->find(
                $escrowId
            );

            if (!$escrow) {

                Logger::write(
                    'escrow_payout',
                    [
                        'step' => 'ESCROW_NOT_FOUND',
                        'id'   => $escrowId
                    ]
                );

                return false;

            }

            /*
            |--------------------------------------------------------------------------
            | Escrow must be confirmed by buyer
            |--------------------------------------------------------------------------
            */

            if (
                ($escrow['status'] ?? '') !== 'buyer_confirmed'
            ) {

                Logger::write(
                    'escrow_payout',
                    [
                        'step'   => 'ESCROW_NOT_READY',
                        'status' => $escrow['status']
                    ]
                );

                return false;

            }

            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate release
            |--------------------------------------------------------------------------
            */

            if (!empty($escrow['released_at'])) {

                Logger::write(
                    'escrow_payout',
                    [
                        'step' => 'ALREADY_RELEASED'
                    ]
                );

                return true;

            }

            /*
            |--------------------------------------------------------------------------
            | Seller Wallet
            |--------------------------------------------------------------------------
            */

            $wallet = $this->wallet->findBySeller(

                (int)$escrow['seller_id']

            );

            if (!$wallet) {

                Logger::write(
                    'escrow_payout',
                    [
                        'step'      => 'WALLET_NOT_FOUND',
                        'seller_id' => $escrow['seller_id']
                    ]
                );

                return false;

            }

            /*
            |--------------------------------------------------------------------------
            | Wallet must be verified
            |--------------------------------------------------------------------------
            */

            if (empty($wallet['verified_at'])) {

                Logger::write(
                    'escrow_payout',
                    [
                        'step' => 'WALLET_NOT_VERIFIED'
                    ]
                );

                return false;

            }

            /*
            |--------------------------------------------------------------------------
            | Create Paystack Recipient (Only Once)
            |--------------------------------------------------------------------------
            */

            if (empty($wallet['recipient_code'])) {

                Logger::write(
                    'escrow_payout',
                    [
                        'step' => 'CREATE_RECIPIENT'
                    ]
                );

                $recipient = $this->transfer->createRecipient(

                    $wallet['account_name'],

                    $wallet['account_number'],

                    $wallet['bank_code'],

                    'NGN'

                );

                if (!($recipient['success'] ?? false)) {

                    Logger::write(
                        'escrow_payout',
                        [
                            'step'     => 'RECIPIENT_FAILED',
                            'response' => $recipient
                        ]
                    );

                    return false;

                }

                $recipientCode =
                    $recipient['data']['recipient_code'];

                $this->wallet->update(

                    (int)$wallet['id'],

                    [

                        'recipient_code' => $recipientCode

                    ]

                );

                $wallet['recipient_code'] =
                    $recipientCode;

                Logger::write(
                    'escrow_payout',
                    [
                        'step'      => 'RECIPIENT_CREATED',
                        'recipient' => $recipientCode
                    ]
                );

            }

            /*
            |--------------------------------------------------------------------------
            | Initiate Transfer
            |--------------------------------------------------------------------------
            */

            $reference =
                'PAYOUT-'
                .date('YmdHis')
                .'-'
                .$escrow['id'];

            Logger::write(
                'escrow_payout',
                [
                    'step'      => 'TRANSFER_START',
                    'reference' => $reference,
                    'amount'    => $escrow['seller_amount']
                ]
            );

            $transfer = $this->transfer->transfer(

                $wallet['recipient_code'],

                (float)$escrow['seller_amount'],

                $reference

            );

            if (!($transfer['success'] ?? false)) {

                Logger::write(
                    'escrow_payout',
                    [
                        'step'     => 'TRANSFER_FAILED',
                        'response' => $transfer
                    ]
                );

                return false;

            }

            /*
             * Continue in Part 2...
             */
             
                         /*
            |--------------------------------------------------------------------------
            | Save Payout Record
            |--------------------------------------------------------------------------
            */

            $transferData = $transfer['data'];

            $this->payout->create([

                'escrow_id'      => (int)$escrow['id'],

                'seller_id'      => (int)$escrow['seller_id'],

                'wallet_id'      => (int)$wallet['id'],

                'amount'         => (float)$escrow['seller_amount'],

                'currency'       => $escrow['currency'] ?? 'NGN',

                'reference'      => $reference,

                'recipient_code' => $wallet['recipient_code'],

                'transfer_code'  => $transferData['transfer_code'] ?? null,

                'status'         => $transferData['status'] ?? 'processing',

                'raw_response'   => json_encode($transferData)

            ]);


            Logger::write(

                'escrow_payout',

                [

                    'step'      => 'PAYOUT_CREATED',

                    'reference' => $reference

                ]

            );


            /*
            |--------------------------------------------------------------------------
            | Update Escrow
            |--------------------------------------------------------------------------
            */

            $this->escrow->update(

                (int)$escrow['id'],

                [

                    'status'           => 'completed',

                    'released_at'      => date('Y-m-d H:i:s'),

                    'payout_reference' => $reference,

                    'released_by'      => $adminId

                ]

            );


            Logger::write(

                'escrow_payout',

                [

                    'step'      => 'ESCROW_COMPLETED',

                    'escrow_id' => $escrow['id']

                ]

            );


            /*
            |--------------------------------------------------------------------------
            | Notify Seller
            |--------------------------------------------------------------------------
            */

            if (

                !$this->notification->exists(

                    (int)$escrow['seller_id'],

                    $reference

                )

            ) {

                $this->notification->create(

                    (int)$escrow['seller_id'],

                    'escrow_payout',

                    'Escrow Payment Released',

                    "🎉 Your escrow payment has been released.\n\n"

                    ."Reference: {$escrow['reference']}\n"

                    ."Amount: ₦"

                    .number_format(

                        (float)$escrow['seller_amount'],

                        2

                    )

                    ."\n\n"

                    ."The payment has been sent to your registered bank account.\n"

                    ."Please allow your bank a few minutes to process it.",

                    $reference

                );

            }


            /*
            |--------------------------------------------------------------------------
            | Notify Buyer
            |--------------------------------------------------------------------------
            */

            if (

                !$this->notification->exists(

                    (int)$escrow['buyer_id'],

                    $reference

                )

            ) {

                $this->notification->create(

                    (int)$escrow['buyer_id'],

                    'escrow_completed',

                    'Escrow Completed',

                    "✅ Your escrow transaction has been completed successfully.\n\n"

                    ."Reference: {$escrow['reference']}\n\n"

                    ."The seller has now been paid.\n\n"

                    ."Thank you for using SENDAM Escrow.",

                    $reference

                );

            }


            Logger::write(

                'escrow_payout',

                [

                    'step'      => 'SUCCESS',

                    'reference' => $reference

                ]

            );


            return true;

        }

        catch (Throwable $e) {

            Logger::write(

                'escrow_payout_error',

                [

                    'message' => $e->getMessage(),

                    'file'    => $e->getFile(),

                    'line'    => $e->getLine()

                ]

            );

            return false;

        }

    }

}