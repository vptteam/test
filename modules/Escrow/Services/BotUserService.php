<?php

declare(strict_types=1);

namespace Modules\Escrow\Services;


use Core\Logger;
use Models\User;

class BotUserService
{
    /**
     * --------------------------------------------------------------------------
     * Synchronise User
     * --------------------------------------------------------------------------
     *
     * Creates the user if they do not exist and returns the database record.
     */
    public function sync(
        array $user,
        array $message
    ): array {

        try {

            $userModel = new User();

            $platformId = (string) (
                $user['platform_id']
                ?? $message['phone']
            );

            $dbUser = $userModel->findOrCreatePlatformUser(

                $message['platform'],

                $platformId,

                $message['phone'] ?? null,

                $user['name'] ?? null

            );

            Logger::write(
                'user_sync',
                [
                    'step'      => 'USER_READY',
                    'user_id'   => $dbUser['id'] ?? null,
                    'platform'  => $message['platform'],
                    'phone'     => $message['phone'] ?? null,
                ]
            );

            return $dbUser;

        } catch (\Throwable $e) {

            Logger::write(
                'user_sync_error',
                [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ]
            );

            return [];

        }

    }

    /**
     * --------------------------------------------------------------------------
     * Find User
     * --------------------------------------------------------------------------
     */
    public function find(
        int $userId
    ): ?array {

        try {

            $user = new User();

            return $user->find($userId);

        } catch (\Throwable $e) {

            Logger::write(
                'user_find_error',
                [
                    'user_id' => $userId,
                    'message' => $e->getMessage(),
                ]
            );

            return null;

        }

    }

    /**
     * --------------------------------------------------------------------------
     * Refresh User
     * --------------------------------------------------------------------------
     *
     * Reload latest database copy.
     */
    public function refresh(
        int $userId
    ): array {

        $user = $this->find($userId);

        return $user ?: [];

    }

    /**
     * --------------------------------------------------------------------------
     * User Exists
     * --------------------------------------------------------------------------
     */
    public function exists(
        int $userId
    ): bool {

        return !empty(
            $this->find($userId)
        );

    }

    /**
     * --------------------------------------------------------------------------
     * Get User Name
     * --------------------------------------------------------------------------
     */
    public function name(
        array $user
    ): string {

        $name = trim(
            (string) ($user['name'] ?? '')
        );

        if ($name === '') {
            return 'there';
        }

        return $name;

    }

    /**
     * --------------------------------------------------------------------------
     * Platform ID
     * --------------------------------------------------------------------------
     */
    public function platformId(
        array $user,
        array $message
    ): string {

        return (string) (

            $user['platform_id']

            ??

            $message['phone']

        );

    }

}