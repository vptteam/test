<?php

declare(strict_types=1);

namespace Modules\Escrow\Handlers;

use Core\Logger;
use Throwable;

class BuyerWaitDeliveryHandler
{
    /**
     * ---------------------------------------------------------
     * WorkflowExecutor Entry Point
     *
     * Active Workflow:
     * BUYER_WAIT_DELIVERY
     * ---------------------------------------------------------
     */
    public function execute(
        $reply,
        array $user,
        array $message,
        string $text,
        array $data = []
    ): bool {

        Logger::write(
            'buyer_wait_delivery_handler',
            [
                'step'    => 'EXECUTE_START',
                'user_id' => $user['id'] ?? null,
                'text'    => $text,
                'data'    => $data
            ]
        );

        try {

            $this->start(
                $reply,
                $user,
                $message,
                $text
            );

            Logger::write(
                'buyer_wait_delivery_handler',
                [
                    'step'    => 'EXECUTE_COMPLETE',
                    'user_id' => $user['id'] ?? null
                ]
            );

            return true;

        } catch (Throwable $e) {

            Logger::write(
                'buyer_wait_delivery_handler_error',
                [
                    'step'    => 'EXECUTE_EXCEPTION',
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine()
                ]
            );

            return false;

        }

    }

    /**
     * ---------------------------------------------------------
     * Buyer Waiting For Delivery
     *
     * Expected Command:
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

        Logger::write(
            'buyer_wait_delivery_handler',
            [
                'step'    => 'START',
                'user_id' => $user['id'] ?? null,
                'text'    => $text
            ]
        );

        (new ReceivedHandler())->start(
            $reply,
            $user,
            $message,
            $text
        );

        Logger::write(
            'buyer_wait_delivery_handler',
            [
                'step'    => 'HANDOFF_COMPLETE',
                'user_id' => $user['id'] ?? null
            ]
        );

    }

}