<?php

declare(strict_types=1);

namespace Modules\Escrow\Services;

use Core\Logger;
use Core\ReplyInterface;
use Modules\Marketplace\Handlers\ReferenceHandler;
use Modules\Escrow\Handlers\EscrowHandler;

class BotReferenceService
{
    /**
     * Handle reference-based commands.
     */
    public function handle(
        array $user,
        array $message,
        ReplyInterface $reply
    ): bool {

        $text = strtoupper(
            trim(
                $message['text'] ?? ''
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Marketplace Listing Reference
        |--------------------------------------------------------------------------
        |
        | Example:
        | SDM-123456
        |
        */

        if (
            preg_match(
                '/^SDM-\d{6}$/',
                $text
            )
        ) {

            Logger::write(
                'reference_service',
                [
                    'step'      => 'LISTING_REFERENCE',
                    'reference' => $text,
                    'user_id'   => $user['id'] ?? null
                ]
            );

            $handler = new ReferenceHandler();

            return $handler->execute(
                $message['phone'],
                $text,
                $reply
            );

        }

        /*
        |--------------------------------------------------------------------------
        | Escrow Reference
        |--------------------------------------------------------------------------
        |
        | Example:
        | ESCROW SDM-123456
        |
        */

        if (
            preg_match(
                '/^(ESCROW|ESROW)\s+(SDM-\d{6})$/i',
                trim($message['text'] ?? ''),
                $matches
            )
        ) {

            Logger::write(
                'reference_service',
                [
                    'step'      => 'ESCROW_REFERENCE',
                    'reference' => strtoupper($matches[2]),
                    'user_id'   => $user['id'] ?? null
                ]
            );

            $handler = new EscrowHandler();

            $handler->start(
                $reply,
                $user,
                $message,
                trim($message['text'])
            );

            return true;

        }

        return false;

    }
}