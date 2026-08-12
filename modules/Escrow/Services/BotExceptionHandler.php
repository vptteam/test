<?php

declare(strict_types=1);

namespace Modules\Escrow\Services;

use Core\Logger;
use Throwable;

class BotExceptionHandler
{
    /**
     * ---------------------------------------------------------
     * Handle Bot Exception
     * ---------------------------------------------------------
     */
    public function handle(
        Throwable $e,
        $reply = null,
        ?string $phone = null
    ): void {

        Logger::write(
            'bot_engine_error',
            [
                'step'    => 'EXCEPTION',
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString()
            ]
        );

        if (
            $reply === null ||
            empty($phone)
        ) {
            return;
        }

        try {

            $reply->text(
                $phone,
                "⚠️ Something went wrong while processing your request.\n\nPlease try again."
            );

            Logger::write(
                'bot_engine_error',
                [
                    'step' => 'ERROR_MESSAGE_SENT'
                ]
            );

        } catch (Throwable $replyException) {

            Logger::write(
                'bot_engine_error',
                [
                    'step'    => 'ERROR_REPLY_FAILED',
                    'message' => $replyException->getMessage(),
                    'file'    => $replyException->getFile(),
                    'line'    => $replyException->getLine()
                ]
            );

        }

    }

}