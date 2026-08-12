<?php

declare(strict_types=1);


namespace Modules\Escrow\Services;

use Core\Logger;
use Models\BotNotification;

class BotNotificationService
{
    /**
     * Send all unread bot notifications to the user.
     */
    public function send(
        int $userId,
        $reply,
        string $phone
    ): void {

        try {

            $notificationModel = new BotNotification();

            while (
                $notification = $notificationModel->unread($userId)
            ) {

                Logger::write(
                    'bot_notification_send',
                    [
                        'step'         => 'NOTIFICATION_FOUND',
                        'user_id'      => $userId,
                        'notification' => $notification,
                    ]
                );

                $reply->text(
                    $phone,
                    $notification['message']
                );

                Logger::write(
                    'bot_notification_send',
                    [
                        'step' => 'MESSAGE_SENT',
                        'notification_id' => $notification['id']
                    ]
                );

                $notificationModel->markRead(
                    (int) $notification['id']
                );

                Logger::write(
                    'bot_notification_send',
                    [
                        'step' => 'MARKED_READ',
                        'notification_id' => $notification['id']
                    ]
                );
            }

        } catch (\Throwable $e) {

            Logger::write(
                'bot_notification_error',
                [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ]
            );
        }
    }
}