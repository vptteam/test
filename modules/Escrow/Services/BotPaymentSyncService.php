<?php

declare(strict_types=1);


namespace Modules\Escrow\Services;

use Core\Logger;
use Controllers\AdvertPaymentController;
use Models\AdvertUpgradePayment;

class BotPaymentSyncService
{
    /**
     * Check pending advert payments and activate package.
     */
    public function sync(
        int $userId,
        $reply = null,
        ?string $phone = null
    ): void {

        try {

            $paymentModel = new AdvertUpgradePayment();

            $pending = $paymentModel->pendingForUser(
                $userId
            );

            if (!$pending) {

                Logger::write(
                    'payment_sync',
                    [
                        'step'    => 'NO_PENDING_PAYMENT',
                        'user_id' => $userId
                    ]
                );

                return;
            }

            Logger::write(
                'payment_sync',
                [
                    'step'      => 'PENDING_PAYMENT_FOUND',
                    'user_id'   => $userId,
                    'reference' => $pending['reference']
                ]
            );

            $controller = new AdvertPaymentController();

            $activated = $controller->activatePayment(
                $pending['reference']
            );

            Logger::write(
                'payment_sync',
                [
                    'step'      => 'ACTIVATION_RESULT',
                    'activated' => $activated,
                    'reference' => $pending['reference']
                ]
            );

            if (
                $activated &&
                $reply &&
                $phone
            ) {

                $reply->text(
                    $phone,
                    "✅ Payment confirmed.\n\n"
                    . "🚀 Your seller package is now active.\n\n"
                    . "Type SELL to continue."
                );

                Logger::write(
                    'payment_sync',
                    [
                        'step' => 'USER_NOTIFIED'
                    ]
                );
            }

        } catch (\Throwable $e) {

            Logger::write(
                'payment_sync_error',
                [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'trace'   => $e->getTraceAsString()
                ]
            );

        }

    }

}