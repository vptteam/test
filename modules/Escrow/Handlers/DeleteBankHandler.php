<?php

declare(strict_types=1);

namespace Modules\Escrow\Handlers;

use Core\Logger;
use Services\Escrow\EscrowWalletService;
use Throwable;

class DeleteBankHandler
{
    protected EscrowWalletService $walletService;

    public function __construct()
    {
        $this->walletService = new EscrowWalletService();
    }

    /**
     * ------------------------------------------------------------------
     * DELETE BANK
     *
     * Supported Commands
     *
     * DELETEBANK
     * REMOVEBANK
     * REMOVE BANK
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
                'delete_bank_handler',
                [
                    'step'      => 'START',
                    'seller_id' => $user['id'] ?? null,
                    'command'   => $text
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Find Existing Wallet
            |--------------------------------------------------------------------------
            */

            $wallet = $this->walletService->findWallet(
                (int) $user['id']
            );

            if (!$wallet) {

                Logger::write(
                    'delete_bank_handler',
                    [
                        'step'      => 'NO_WALLET_FOUND',
                        'seller_id' => $user['id']
                    ]
                );

                $reply->text(
                    $message['phone'],
                    "❌ You have not registered any escrow payout bank account.\n\n".
                    "To register one, send:\n\n".
                    "BANK GTBank 0123456789"
                );

                return;
            }

            Logger::write(
                'delete_bank_handler',
                [
                    'step'      => 'WALLET_FOUND',
                    'seller_id' => $user['id'],
                    'wallet_id' => $wallet['id'],
                    'bank'      => $wallet['bank_name'] ?? null,
                    'account'   => $wallet['account_number'] ?? null
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Delete Wallet
            |--------------------------------------------------------------------------
            */

            $deleted = $this->walletService->removeWallet(
                (int) $user['id']
            );

            Logger::write(
                'delete_bank_handler',
                [
                    'step'      => 'DELETE_ATTEMPT',
                    'seller_id' => $user['id'],
                    'wallet_id' => $wallet['id'],
                    'success'   => $deleted
                ]
            );

            if (!$deleted) {

                Logger::write(
                    'delete_bank_handler',
                    [
                        'step'      => 'DELETE_FAILED',
                        'seller_id' => $user['id'],
                        'wallet_id' => $wallet['id']
                    ]
                );

                $reply->text(
                    $message['phone'],
                    "❌ Unable to remove your payout bank account at the moment.\n\n".
                    "Please try again later."
                );

                return;
            }
                        Logger::write(
                'delete_bank_handler',
                [
                    'step'      => 'DELETE_SUCCESS',
                    'seller_id' => $user['id'],
                    'wallet_id' => $wallet['id'],
                    'bank'      => $wallet['bank_name'] ?? null,
                    'account'   => $wallet['account_number'] ?? null
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Success Reply
            |--------------------------------------------------------------------------
            */

            $reply->text(

                $message['phone'],

                "🗑 *Payout Bank Removed*\n\n".

                "Your escrow payout bank account has been removed successfully.\n\n".

                "You will not receive escrow payouts until another payout bank account is registered.\n\n".

                "To register a new account, reply:\n\n".

                "BANK GTBank 0123456789"

            );

            Logger::write(
                'delete_bank_handler',
                [
                    'step'      => 'REPLY_SENT',
                    'seller_id' => $user['id']
                ]
            );

            Logger::write(
                'delete_bank_handler',
                [
                    'step'      => 'COMPLETE',
                    'seller_id' => $user['id']
                ]
            );

        }

        catch (Throwable $e) {

            Logger::write(

                'delete_bank_handler_error',

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

                    "❌ An unexpected error occurred while removing your payout bank account.\n\n".

                    "Please try again later."

                );

            }

            catch (Throwable $ignore) {

                Logger::write(

                    'delete_bank_handler_error',

                    [

                        'step' => 'REPLY_FAILED'

                    ]

                );

            }

        }

    }

}