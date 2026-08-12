<?php

declare(strict_types=1);

namespace Modules;

use Core\Logger;
use Models\Conversation;

use Modules\Profile\Handlers\ProfileHandler;
use Modules\Escrow\Handlers\EscrowHandler;
use Modules\Marketplace\Handlers\PhotosHandler;
use Modules\Marketplace\Handlers\UpgradePackageHandler;
use Modules\Escrow\EscrowCommandRouter;

use Services\Marketplace\AdvertQuotaService;

class BotCommandRouter
{

    /**
     * --------------------------------------------------------------------------
     * Route Command
     * --------------------------------------------------------------------------
     */
    public function handle(
        string $text,
        array $user,
        array $message,
        $reply
    ): bool {

        $command = $this->detectCommand($text);

        Logger::write(
            'bot_command_router',
            [
                'step'    => 'COMMAND_DETECTED',
                'command' => $command,
                'text'    => $text,
                'user_id' => $user['id'] ?? null
            ]
        );

        if ($command === null) {
            return false;
        }

        switch ($command) {

            case 'start':
                return $this->start(
                    $reply,
                    $message
                );

            case 'profile':
                return $this->profile(
                    $reply,
                    $user,
                    $message
                );

            case 'sell':
                return $this->sell(
                    $reply,
                    $user,
                    $message
                );

            case 'upgrade':
                return $this->upgrade(
                    $reply,
                    $user,
                    $message
                );

            case 'escrow':
                return $this->escrow(
                    $reply,
                    $user,
                    $message,
                    $text
                );

        }

        return false;

    }



    /**
     * --------------------------------------------------------------------------
     * Detect Command
     * --------------------------------------------------------------------------
     */
    protected function detectCommand(
        string $text
    ): ?string {

        $text = strtolower(trim($text));

        /*
        |--------------------------------------------------------------------------
        | START / MENU
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $text,
                [
                    '/start',
                    'start',
                    '/menu',
                    'menu'
                ],
                true
            )
        ) {
            return 'start';
        }

        /*
        |--------------------------------------------------------------------------
        | PROFILE
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $text,
                [
                    'profile',
                    '/profile',
                    'account',
                    'my profile',
                    'id',
                    '/id'
                ],
                true
            )
        ) {
            return 'profile';
        }

        /*
        |--------------------------------------------------------------------------
        | SELL
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $text,
                [
                    'sell',
                    '/sell',
                    'create advert',
                    'post advert',
                    'advertise',
                    'post item'
                ],
                true
            )
        ) {
            return 'sell';
        }

        /*
        |--------------------------------------------------------------------------
        | UPGRADE
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $text,
                [
                    'upgrade',
                    '/upgrade',
                    'upgrade package',
                    'upgrade seller',
                    'buy package',
                    'increase limit'
                ],
                true
            )
        ) {
            return 'upgrade';
        }

        /*
        |--------------------------------------------------------------------------
        | ESCROW
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                $text,
                [
                    'escrow',
                    '/escrow',
                    'pay escrow',
                    'buyer protection',
                    'my escrow',
                    'escrow history'
                ],
                true
            )
        ) {
            return 'escrow';
        }
/*
|--------------------------------------------------------------------------
| ESCROW COMMANDS
|--------------------------------------------------------------------------
*/

$text = trim($text);

if ($text === '') {

    Logger::write(
        'bot_command_router',
        [
            'step' => 'EMPTY_COMMAND'
        ]
    );

    return null;

}

$firstWord = strtok($text, ' ');

if ($firstWord === false) {

    Logger::write(
        'bot_command_router',
        [
            'step' => 'NO_FIRST_WORD',
            'text' => $text
        ]
    );

    return null;

}

$firstWord = strtoupper($firstWord);

Logger::write(
    'bot_command_router',
    [
        'step'       => 'FIRST_WORD',
        'first_word' => $firstWord,
        'text'       => $text
    ]
);

if (

    in_array(

        $firstWord,

        [

            'ESCROW',
            'SHIP',
            'RECEIVED',
            'BANK',
            'BANKS',
            'APPROVE',
            'PAY',
            'REFUND',
            'HOLD',
            'DISPUTE'

        ],

        true

    )

) {

    return 'escrow';

}

return null;

}
        /**
     * --------------------------------------------------------------------------
     * START / MENU
     * --------------------------------------------------------------------------
     */
    protected function start(
        $reply,
        array $message
    ): bool {

        try {

            Logger::write(
                'bot_command_router',
                [
                    'step' => 'START_COMMAND'
                ]
            );

            $menu = new \Modules\Escrow\Services\BotMenuService();

            $menu->main(
                $reply,
                $message,
                "👋 Welcome to SENDAM 🇳🇬"
            );

            return true;

        } catch (\Throwable $e) {

            Logger::write(
                'bot_command_router_error',
                [
                    'step'    => 'START_FAILED',
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine()
                ]
            );

            $reply->text(
                $message['phone'],
                "⚠️ Unable to load the main menu."
            );

            return true;

        }

    }



    /**
     * --------------------------------------------------------------------------
     * PROFILE
     * --------------------------------------------------------------------------
     */
    protected function profile(
        $reply,
        array $user,
        array $message
    ): bool {

        try {

            Logger::write(
                'bot_command_router',
                [
                    'step'    => 'PROFILE_COMMAND',
                    'user_id' => $user['id']
                ]
            );

            $handler = new ProfileHandler();

            $handler->show(
                $reply,
                (int) $user['id'],
                $message['phone']
            );

            return true;

        } catch (\Throwable $e) {

            Logger::write(
                'profile_handler_error',
                [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine()
                ]
            );

            $reply->text(
                $message['phone'],
                "⚠️ Unable to load your profile."
            );

            return true;

        }

    }
        /**
     * --------------------------------------------------------------------------
     * SELL
     * --------------------------------------------------------------------------
     */
    protected function sell(
        $reply,
        array $user,
        array $message
    ): bool {

        try {

            Logger::write(
                'sell_workflow',
                [
                    'step'    => 'START',
                    'user_id' => $user['id']
                ]
            );

            $subscription = new \Models\SellerSubscription();

$check = $subscription->canCreateAdvert(
    (int)$user['id']
);

if (!$check['success']) {

    Logger::write(
        'sell_workflow',
        [
            'step'    => 'SELL_BLOCKED',
            'user_id' => $user['id'],
            'check'   => $check
        ]
    );

    $reply->text(
        $message['phone'],
        $check['message']
    );

    return true;
}

            $conversation = new Conversation();

            $conversation->cancel(
                (int) $user['id']
            );

            $conversation->start(
                (int) $user['id'],
                'Marketplace',
                'create_listing',
                'photos'
            );

            Logger::write(
                'sell_workflow',
                [
                    'step' => 'LISTING_STARTED'
                ]
            );

            $handler = new PhotosHandler();

            $handler->ask(
                $reply,
                $message['phone']
            );

            return true;

        } catch (\Throwable $e) {

            Logger::write(
                'sell_workflow_error',
                [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine()
                ]
            );

            $reply->text(
                $message['phone'],
                "⚠️ Unable to start advert creation."
            );

            return true;

        }

    }



    /**
     * --------------------------------------------------------------------------
     * UPGRADE
     * --------------------------------------------------------------------------
     */
    protected function upgrade(
        $reply,
        array $user,
        array $message
    ): bool {

        try {

            Logger::write(
                'upgrade_workflow',
                [
                    'step'    => 'START',
                    'user_id' => $user['id']
                ]
            );

            $conversation = new Conversation();

            $conversation->cancel(
                (int) $user['id']
            );

            $conversation->start(
                (int) $user['id'],
                'Marketplace',
                'upgrade_package',
                'upgrade_package'
            );

            $handler = new UpgradePackageHandler();

            $handler->ask(
                $reply,
                $message['phone']
            );

            return true;

        } catch (\Throwable $e) {

            Logger::write(
                'upgrade_workflow_error',
                [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine()
                ]
            );

            $reply->text(
                $message['phone'],
                "⚠️ Unable to start upgrade process."
            );

            return true;

        }

    }
    
    /**
     * --------------------------------------------------------------------------
     * ESCROW
     * --------------------------------------------------------------------------
     */
    protected function escrow(
    $reply,
    array $user,
    array $message,
    string $text
): bool {

    try {

        Logger::write(
            'escrow_router',
            [
                'step'    => 'ROUTING_ESCROW_COMMAND',
                'text'    => $text,
                'user_id' => $user['id'] ?? null
            ]
        );

        $router = new EscrowCommandRouter();

        if (

            $router->handle(

                $text,

                $user,

                $message,

                $reply

            )

        ) {

            Logger::write(
                'escrow_router',
                [
                    'step' => 'COMMAND_HANDLED'
                ]
            );

            return true;

        }

        Logger::write(
            'escrow_router',
            [
                'step' => 'UNKNOWN_ESCROW_COMMAND'
            ]
        );

        $reply->text(

            $message['phone'],

            "❌ Unknown escrow command."

        );

        return true;

    }

    catch (\Throwable $e) {

        Logger::write(

            'escrow_router_error',

            [

                'message' => $e->getMessage(),

                'file' => $e->getFile(),

                'line' => $e->getLine()

            ]

        );

        $reply->text(

            $message['phone'],

            "⚠️ Unable to process escrow command."

        );

        return true;

    }

}

}