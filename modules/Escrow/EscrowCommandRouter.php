<?php

declare(strict_types=1);

namespace Modules\Escrow;

use Core\Logger;
use Modules\Escrow\Handlers\EscrowHandler;
use Modules\Escrow\Handlers\ShipHandler;
use Modules\Escrow\Handlers\ReceivedHandler;
use Modules\Escrow\Handlers\BankHandler;
use Modules\Escrow\Handlers\BanksHandler;
use Modules\Escrow\Handlers\AdminEscrowHandler;
use Throwable;

class EscrowCommandRouter
{
    public function handle(
        string $text,
        array $user,
        array $message,
        $reply
    ): bool {

        try {

            Logger::write(
                'escrow_command_router',
                [
                    'step'    => 'START',
                    'text'    => $text,
                    'user_id' => $user['id'] ?? null,
                    'phone'   => $message['phone'] ?? null
                ]
            );

            $command = strtoupper(
                strtok(trim($text), ' ')
            );

            Logger::write(
                'escrow_command_router',
                [
                    'step'    => 'COMMAND_DETECTED',
                    'command' => $command
                ]
            );

            switch ($command) {

                /*
                |--------------------------------------------------------------------------
                | ESCROW
                |--------------------------------------------------------------------------
                */

                case 'ESCROW':

                    Logger::write(
                        'escrow_command_router',
                        [
                            'step'    => 'ROUTE_ESCROW',
                            'handler' => 'EscrowHandler'
                        ]
                    );

                    (new EscrowHandler())->start(
                        $reply,
                        $user,
                        $message,
                        $text
                    );

                    return true;

                /*
                |--------------------------------------------------------------------------
                | SHIP
                |--------------------------------------------------------------------------
                */

                case 'SHIP':

                    Logger::write(
                        'escrow_command_router',
                        [
                            'step'    => 'ROUTE_SHIP',
                            'handler' => 'ShipHandler'
                        ]
                    );

                    (new ShipHandler())->start(
                        $reply,
                        $user,
                        $message,
                        $text
                    );

                    return true;

                /*
                |--------------------------------------------------------------------------
                | RECEIVED
                |--------------------------------------------------------------------------
                */

                case 'RECEIVED':

                    Logger::write(
                        'escrow_command_router',
                        [
                            'step'    => 'ROUTE_RECEIVED',
                            'handler' => 'ReceivedHandler'
                        ]
                    );

                    (new ReceivedHandler())->start(
                        $reply,
                        $user,
                        $message,
                        $text
                    );

                    return true;

                /*
                |--------------------------------------------------------------------------
                | BANK
                |--------------------------------------------------------------------------
                */

                case 'BANK':

                    Logger::write(
                        'escrow_command_router',
                        [
                            'step'    => 'ROUTE_BANK',
                            'handler' => 'BankHandler'
                        ]
                    );

                    (new BankHandler())->start(
                        $reply,
                        $user,
                        $message,
                        $text
                    );

                    return true;

                /*
                |--------------------------------------------------------------------------
                | BANKS
                |--------------------------------------------------------------------------
                */

                case 'BANKS':

                    Logger::write(
                        'escrow_command_router',
                        [
                            'step'    => 'ROUTE_BANKS',
                            'handler' => 'BanksHandler'
                        ]
                    );

                    (new BanksHandler())->start(
                        $reply,
                        $user,
                        $message,
                        $text
                    );

                    return true;

                /*
                |--------------------------------------------------------------------------
                | ADMIN COMMANDS
                |--------------------------------------------------------------------------
                */

                case 'APPROVE':
                case 'PAY':
                case 'REFUND':
                case 'HOLD':
                case 'DISPUTE':

                    Logger::write(
                        'escrow_command_router',
                        [
                            'step'    => 'ROUTE_ADMIN',
                            'command' => $command,
                            'handler' => 'AdminEscrowHandler'
                        ]
                    );

                    (new AdminEscrowHandler())->start(
                        $reply,
                        $user,
                        $message,
                        $text
                    );

                    return true;

                default:

                    Logger::write(
                        'escrow_command_router',
                        [
                            'step'    => 'UNKNOWN_COMMAND',
                            'command' => $command,
                            'text'    => $text
                        ]
                    );

                    return false;

            }

        } catch (Throwable $e) {

            Logger::write(
                'escrow_command_router_error',
                [
                    'step'    => 'EXCEPTION',
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'trace'   => $e->getTraceAsString()
                ]
            );

            try {

                $reply->text(
                    $message['phone'] ?? '',
                    "⚠️ Unable to process escrow command."
                );

            } catch (Throwable $ignore) {
                // Ignore reply failures.
            }

            return true;
        }
    }
}