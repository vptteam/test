<?php

declare(strict_types=1);

namespace Modules\Escrow\Handlers;

use Core\Logger;
use Throwable;

class SellerDeliveryHandler
{
    /**
     * ---------------------------------------------------------
     * WorkflowExecutor entry point
     *
     * Workflow:
     * BUYER pays
     * SELLER ships
     * BUYER confirms
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
            'seller_delivery_handler',
            [
                'step'    => 'EXECUTE',
                'user_id' => $user['id'] ?? null,
                'text'    => $text
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
                'seller_delivery_handler',
                [
                    'step'    => 'COMPLETE',
                    'user_id' => $user['id'] ?? null
                ]
            );

            return true;

        } catch (Throwable $e) {

            Logger::write(
                'seller_delivery_handler_error',
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
                "❌ Unable to process shipment."
            );

            return false;

        }

    }

    /**
     * ---------------------------------------------------------
     * Start Seller Shipment
     * ---------------------------------------------------------
     */
    public function start(
        $reply,
        array $user,
        array $message,
        string $text
    ): void {

        Logger::write(
            'seller_delivery_handler',
            [
                'step'    => 'START_HANDLER',
                'user_id' => $user['id'] ?? null
            ]
        );

        (new ShipHandler())->start(
            $reply,
            $user,
            $message,
            $text
        );

    }
}