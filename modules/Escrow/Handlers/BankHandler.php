<?php

declare(strict_types=1);

namespace Modules\Escrow\Handlers;

use Core\Logger;
use Services\Escrow\EscrowWalletService;
use Models\Conversation;
use Models\BotNotification;
use Modules\Escrow\Handlers\BanksHandler;
use Throwable;

class BankHandler
{
    protected EscrowWalletService $walletService;

    public function __construct()
    {
        $this->walletService = new EscrowWalletService();
    }

    /**
     * ------------------------------------------------------------------
     * BANK COMMAND
     *
     * Supported Commands
     *
     * BANK
     * BANK GTBank 0123456789
     * BANK Access Bank 0123456789
     * BANK 058 0123456789
     * ------------------------------------------------------------------
     */
    public function start(
        $reply,
        array $user,
        array $message,
        string $text
    ): void {

        try {

            Logger::write(
                'bank_handler',
                [
                    'step'      => 'START',
                    'seller_id' => $user['id'] ?? null,
                    'text'      => $text
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
                'bank_handler',
                [
                    'step'  => 'COMMAND_PARSED',
                    'parts' => $parts
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Help
            |--------------------------------------------------------------------------
            */

            if (count($parts) < 3) {

                Logger::write(
                    'bank_handler',
                    [
                        'step' => 'HELP_SHOWN'
                    ]
                );

                $reply->text(

                    $message['phone'],

                    "🏦 *ESCROW BANK REGISTRATION*\n\n".

                    "Register the bank account where you want to receive escrow payouts.\n\n".

                    "*Examples*\n\n".

                    "BANK BANKCODE ACCOUNT NUMBER\n\n".

                    "GTBank 058 0123456789\n\n".

                    "Use BANKS to see supported Nigerian bank codes."

                );

                return;

            }

            /*
            |--------------------------------------------------------------------------
            | Last Value = Account Number
            |--------------------------------------------------------------------------
            */

            $accountNumber = trim(
                end($parts)
            );

            Logger::write(
                'bank_handler',
                [
                    'step'           => 'ACCOUNT_NUMBER_PARSED',
                    'account_number' => $accountNumber
                ]
            );

            if (
                !preg_match(
                    '/^[0-9]{10}$/',
                    $accountNumber
                )
            ) {

                Logger::write(
                    'bank_handler',
                    [
                        'step'           => 'INVALID_ACCOUNT_NUMBER',
                        'account_number' => $accountNumber
                    ]
                );

                $reply->text(

                    $message['phone'],

                    "❌ Invalid account number.\n\n".

                    "A Nigerian bank account must contain exactly 10 digits."

                );

                return;

            }

            /*
            |--------------------------------------------------------------------------
            | Remove Command
            |--------------------------------------------------------------------------
            */

            array_shift($parts);

            /*
            |--------------------------------------------------------------------------
            | Remove Account Number
            |--------------------------------------------------------------------------
            */

            array_pop($parts);

            /*
            |--------------------------------------------------------------------------
            | Bank Input
            |--------------------------------------------------------------------------
            */

            $bankInput = trim(
                implode(' ', $parts)
            );

            Logger::write(
                'bank_handler',
                [
                    'step'      => 'BANK_INPUT',
                    'seller_id' => $user['id'],
                    'value'     => $bankInput
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Resolve Bank Code / Name
            |--------------------------------------------------------------------------
            */

            $bankCode = null;
            $bankName = null;
            
                        /*
            |--------------------------------------------------------------------------
            | Numeric Bank Code
            |--------------------------------------------------------------------------
            */

            if (
                preg_match(
                    '/^[0-9]{3}$/',
                    $bankInput
                )
            ) {

                Logger::write(
                    'bank_handler',
                    [
                        'step'      => 'LOOKUP_BANK_CODE',
                        'seller_id' => $user['id'],
                        'bank_code' => $bankInput
                    ]
                );

                $bankCode = $bankInput;

                $banks = $this->walletService->banks();

                Logger::write(
                    'bank_handler',
                    [
                        'step'    => 'BANK_LIST_FETCHED',
                        'success' => $banks['success'] ?? false
                    ]
                );

                if (!($banks['success'] ?? false)) {

                    $reply->text(

                        $message['phone'],

                        "❌ Unable to retrieve the bank list.\n\n".

                        "Please try again later."

                    );

                    return;

                }

                foreach ($banks['data'] as $bank) {

                    if (
                        ($bank['code'] ?? '') === $bankCode
                    ) {

                        $bankName = $bank['name'];

                        break;

                    }

                }

            }

            /*
            |--------------------------------------------------------------------------
            | Bank Name Lookup
            |--------------------------------------------------------------------------
            */

            else {

                Logger::write(
                    'bank_handler',
                    [
                        'step'      => 'LOOKUP_BANK_NAME',
                        'seller_id' => $user['id'],
                        'bank_name' => $bankInput
                    ]
                );

                $banks = $this->walletService->banks();

                Logger::write(
                    'bank_handler',
                    [
                        'step'    => 'BANK_LIST_FETCHED',
                        'success' => $banks['success'] ?? false
                    ]
                );

                if (!($banks['success'] ?? false)) {

                    $reply->text(

                        $message['phone'],

                        "❌ Unable to retrieve Nigerian banks."

                    );

                    return;

                }

                foreach ($banks['data'] as $bank) {

                    if (

                        strcasecmp(

                            trim($bank['name']),

                            trim($bankInput)

                        ) === 0

                    ) {

                        $bankCode = $bank['code'];

                        $bankName = $bank['name'];

                        break;

                    }

                }

            }

            /*
            |--------------------------------------------------------------------------
            | Bank Not Found
            |--------------------------------------------------------------------------
            */

            if (empty($bankCode)) {

                Logger::write(
                    'bank_handler',
                    [
                        'step'      => 'BANK_NOT_FOUND',
                        'seller_id' => $user['id'],
                        'input'     => $bankInput
                    ]
                );

                $reply->text(

                    $message['phone'],

                    "❌ Bank not recognised.\n\n".

                    "Send BANKS to see the list of supported Nigerian banks."

                );

                return;

            }

            Logger::write(

                'bank_handler',

                [

                    'step'      => 'BANK_MATCHED',

                    'seller_id' => $user['id'],

                    'bank_code' => $bankCode,

                    'bank_name' => $bankName

                ]

            );

            /*
            |--------------------------------------------------------------------------
            | Register Wallet
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'bank_handler',
                [
                    'step'      => 'REGISTER_WALLET',
                    'seller_id' => $user['id']
                ]
            );

            $result = $this->walletService->registerWallet(

                (int)$user['id'],

                $bankCode,

                $accountNumber

            );

            Logger::write(
                'bank_handler',
                [
                    'step'    => 'REGISTER_RESULT',
                    'success' => $result['success'] ?? false,
                    'message' => $result['message'] ?? null
                ]
            );

            if (!($result['success'] ?? false)) {

                Logger::write(

                    'bank_handler',

                    [

                        'step'      => 'REGISTER_FAILED',

                        'seller_id' => $user['id'],

                        'message'   => $result['message'] ?? null

                    ]

                );

                $reply->text(

                    $message['phone'],

                    "❌ " . ($result['message'] ?? 'Unable to register your bank account.')

                );

                return;

            }

            $wallet = $result['wallet'] ?? [];

            Logger::write(

                'bank_handler',

                [

                    'step'      => 'REGISTER_SUCCESS',

                    'seller_id' => $user['id'],

                    'wallet_id' => $wallet['id'] ?? null,

                    'recipient' => $wallet['recipient_code'] ?? null

                ]

            );
                        /*
            |--------------------------------------------------------------------------
            | Complete Bank Conversation Workflow
            |--------------------------------------------------------------------------
            */

            try {

                $conversation = new Conversation();

                $activeConversation = $conversation->active(

                    (int)$user['id']

                );


                if (

                    $activeConversation

                    &&

                    $activeConversation['module'] === 'Escrow'

                ) {

                    $conversation->finish(

                        (int)$activeConversation['id']

                    );


                    Logger::write(

                        'bank_handler',

                        [

                            'step' => 'BANK_WORKFLOW_COMPLETED',

                            'conversation_id' => $activeConversation['id'],

                            'seller_id' => $user['id']

                        ]

                    );

                }


            }

            catch (Throwable $e) {


                Logger::write(

                    'bank_handler',

                    [

                        'step'    => 'BANK_WORKFLOW_FINISH_FAILED',

                        'message' => $e->getMessage(),

                        'line'    => $e->getLine()

                    ]

                );


            }


            /*
            |--------------------------------------------------------------------------
            | Notify Admin
            |--------------------------------------------------------------------------
            |
            | Admin needs to know seller has a verified payout account.
            |
            |--------------------------------------------------------------------------
            */


            try {


                $notification = new BotNotification();


                $adminId = defined('ESCROW_ADMIN_ID')

                    ? (int) ESCROW_ADMIN_ID

                    : 1;



                $reference =

                    'BANK_VERIFIED_' .

                    $user['id'];



                if (

                    !$notification->exists(

                        $adminId,

                        $reference

                    )

                ) {


                    $created = $notification->create(

                        $adminId,

                        'escrow_bank_verified',

                        "🏦 Seller payout account verified.\n\n".

                        "Seller ID: {$user['id']}\n\n".

                        "Bank: ".

                        ($wallet['bank_name'] ?? $bankName).

                        "\n\n".

                        "Account: ".

                        ($wallet['account_number'] ?? $accountNumber).

                        "\n\n".

                        "The seller is now ready for escrow payout review.",

                        $reference

                    );


                    Logger::write(

                        'bank_handler',

                        [

                            'step'      => 'ADMIN_NOTIFICATION_CREATED',

                            'success'   => $created,

                            'admin_id'  => $adminId,

                            'reference' => $reference

                        ]

                    );


                }

                else {


                    Logger::write(

                        'bank_handler',

                        [

                            'step' => 'ADMIN_NOTIFICATION_EXISTS',

                            'reference' => $reference

                        ]

                    );


                }


            }

            catch (Throwable $e) {


                Logger::write(

                    'bank_handler',

                    [

                        'step'    => 'ADMIN_NOTIFICATION_FAILED',

                        'message' => $e->getMessage(),

                        'line'    => $e->getLine()

                    ]

                );


            }



            /*
            |--------------------------------------------------------------------------
            | Seller Success Reply
            |--------------------------------------------------------------------------
            */


            $reply->text(

                $message['phone'],


                "✅ *BANK ACCOUNT VERIFIED*\n\n".

                "Your escrow payout account has been verified successfully.\n\n".

                "🏦 *Bank*\n".

                ($wallet['bank_name'] ?? $bankName).

                "\n\n".

                "👤 *Account Name*\n".

                ($wallet['account_name'] ?? 'N/A').

                "\n\n".

                "💳 *Account Number*\n".

                ($wallet['account_number'] ?? $accountNumber).

                "\n\n".

                "🟢 *Status*\n".

                "Verified\n\n".

                "Your payout account has now been linked to your SENDAM Escrow profile.\n\n".

                "📋 *What happens next?*\n\n".

                "• Your escrow transaction is now awaiting SENDAM Admin review.\n".

                "• Once approved, payment will be transferred automatically to this verified bank account.\n\n".
                
                 "• Escrow fee of 5% applies.\n\n".

                "No further action is required unless SENDAM contacts you for additional information.\n\n".

                "Thank you for using SENDAM Escrow."

            );


            Logger::write(

                'bank_handler',

                [

                    'step'      => 'COMPLETE',

                    'seller_id' => $user['id'],

                    'wallet_id' => $wallet['id'] ?? null

                ]

            );


        }

        catch (Throwable $e) {


            Logger::write(

                'bank_handler_error',

                [

                    'step'    => 'EXCEPTION',

                    'message' => $e->getMessage(),

                    'file'    => $e->getFile(),

                    'line'    => $e->getLine()

                ]

            );


            try {


                $reply->text(

                    $message['phone'],

                    "❌ An unexpected error occurred while registering your bank account.\n\n".

                    "Please try again later."

                );


            }

            catch (Throwable $ignore) {


                Logger::write(

                    'bank_handler_error',

                    [

                        'step' => 'REPLY_FAILED'

                    ]

                );


            }


        }

    }

}
