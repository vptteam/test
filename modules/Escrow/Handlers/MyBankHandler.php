<?php

declare(strict_types=1);

namespace Modules\Escrow\Handlers;

use Core\Logger;
use Services\Escrow\EscrowWalletService;
use Throwable;

class MyBankHandler
{
    protected EscrowWalletService $walletService;

    public function __construct()
    {
        $this->walletService = new EscrowWalletService();
    }

    /**
     * ---------------------------------------------------------
     * MYBANK
     * View registered escrow payout account
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
                'my_bank_handler',
                [
                    'step'    => 'START',
                    'user_id' => $user['id'] ?? null
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Load Wallet
            |--------------------------------------------------------------------------
            */

            $wallet = $this->walletService->findWallet(
                (int)$user['id']
            );

            if (!$wallet) {

                Logger::write(
                    'my_bank_handler',
                    [
                        'step'    => 'NO_WALLET',
                        'user_id' => $user['id']
                    ]
                );

                $reply->text(
                    $message['phone'],
                    "🏦 *NO ESCROW BANK ACCOUNT*\n\n".
                    "You have not registered a payout bank account yet.\n\n".
                    "To register one, reply:\n\n".
                    "BANK GTBank 0123456789"
                );

                return;

            }

            Logger::write(
                'my_bank_handler',
                [
                    'step'      => 'WALLET_FOUND',
                    'wallet_id' => $wallet['id'],
                    'user_id'   => $user['id']
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Verification Status
            |--------------------------------------------------------------------------
            */

            $verified = !empty($wallet['verified_at']);

            $status = $verified
                ? "✅ Verified"
                : "❌ Not Verified";

            $recipientReady = !empty($wallet['recipient_code']);

            $recipient = $recipientReady
                ? "✅ Ready for payouts"
                : "❌ Not Ready";

            Logger::write(
                'my_bank_handler',
                [
                    'step'            => 'STATUS_PREPARED',
                    'verified'        => $verified,
                    'recipient_ready' => $recipientReady
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Reply
            |--------------------------------------------------------------------------
            */

            $reply->text(

                $message['phone'],

                "🏦 *YOUR ESCROW PAYOUT ACCOUNT*\n\n".

                "🏦 *Bank*\n".
                ($wallet['bank_name'] ?? 'N/A')."\n\n".

                "👤 *Account Name*\n".
                ($wallet['account_name'] ?? 'N/A')."\n\n".

                "💳 *Account Number*\n".
                ($wallet['account_number'] ?? 'N/A')."\n\n".

                "📌 *Verification*\n".
                $status."\n\n".

                "💸 *Payout Status*\n".
                $recipient."\n\n".

                "━━━━━━━━━━━━━━\n".

                "To update this account:\n".
                "BANK GTBank 0123456789\n\n".

                "To remove this account:\n".
                "DELETEBANK"

            );

            Logger::write(
                'my_bank_handler',
                [
                    'step'      => 'COMPLETE',
                    'wallet_id' => $wallet['id'],
                    'user_id'   => $user['id']
                ]
            );

        }

        catch (Throwable $e) {

            Logger::write(
                'my_bank_handler_error',
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
                "❌ Unable to retrieve your payout bank details.\n\nPlease try again later."
            );

        }

    }

}