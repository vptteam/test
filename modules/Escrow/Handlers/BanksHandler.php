<?php

declare(strict_types=1);

namespace Modules\Escrow\Handlers;

use Core\Logger;
use Services\Escrow\EscrowWalletService;
use Throwable;

class BanksHandler
{
    protected EscrowWalletService $walletService;

    public function __construct()
    {
        $this->walletService = new EscrowWalletService();
    }

    /**
     * ---------------------------------------------------------
     * BANKS
     *
     * Lists Nigerian banks supported by Paystack
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
                'banks_handler',
                [
                    'step'    => 'START',
                    'user_id' => $user['id'] ?? null
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Retrieve Banks
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'banks_handler',
                [
                    'step' => 'FETCH_BANKS'
                ]
            );

            $banks = $this->walletService->banks();

            Logger::write(
                'banks_handler',
                [
                    'step'    => 'FETCH_RESULT',
                    'success' => $banks['success'] ?? false,
                    'count'   => isset($banks['data'])
                        ? count($banks['data'])
                        : 0
                ]
            );

            if (!($banks['success'] ?? false)) {

                Logger::write(
                    'banks_handler',
                    [
                        'step' => 'FETCH_FAILED'
                    ]
                );

                $reply->text(
                    $message['phone'],
                    "❌ Unable to retrieve the list of supported banks.\n\n".
                    "Please try again later."
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Build Bank List
            |--------------------------------------------------------------------------
            */

            $rows = [];

            foreach ($banks['data'] as $bank) {

                $rows[] = sprintf(
                    "%s - %s",
                    $bank['code'] ?? '---',
                    $bank['name'] ?? 'Unknown'
                );

            }

            sort($rows, SORT_NATURAL | SORT_FLAG_CASE);

            Logger::write(
                'banks_handler',
                [
                    'step'        => 'BANK_LIST_READY',
                    'total_banks' => count($rows)
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Send In Chunks
            |--------------------------------------------------------------------------
            */

            $chunks = array_chunk($rows, 25);

            $total = count($chunks);

            foreach ($chunks as $index => $chunk) {

                $body = implode("\n", $chunk);

                $footer = "";

                if ($index === ($total - 1)) {

                    $footer =
                        "\n\n━━━━━━━━━━━━━━\n".
                        "💡 *Tip*\n".
                        "You don't have to remember bank codes.\n\n".
                        "You can request for all codes by sending:\n\n".
                        "BANKS\n\n".
                        "Then send the BANK CODE with your account as below\n\n".
                        "BANK 058 0123456789";

                }

                Logger::write(
                    'banks_handler',
                    [
                        'step'    => 'SEND_CHUNK',
                        'chunk'   => $index + 1,
                        'total'   => $total
                    ]
                );

                $reply->text(
                    $message['phone'],
                    "🏦 *SUPPORTED NIGERIAN BANKS*\n\n".
                    $body.
                    $footer
                );
            }

            Logger::write(
                'banks_handler',
                [
                    'step'        => 'COMPLETE',
                    'user_id'     => $user['id'] ?? null,
                    'total_banks' => count($rows),
                    'messages'    => $total
                ]
            );

        }

        catch (Throwable $e) {

            Logger::write(
                'banks_handler_error',
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
                    "❌ Unable to retrieve the supported bank list.\n\n".
                    "Please try again later."
                );

            } catch (Throwable $ignore) {

                Logger::write(
                    'banks_handler_error',
                    [
                        'step' => 'REPLY_FAILED'
                    ]
                );

            }

        }

    }

}