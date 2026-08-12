<?php

declare(strict_types=1);

namespace Modules\Escrow\Services;

use Core\Logger;

class BotGreetingService
{
    /**
     * --------------------------------------------------------------------------
     * Handle Greetings
     * --------------------------------------------------------------------------
     *
     * Returns TRUE if the message was handled.
     */
    public function handle(
        string $text,
        array $user,
        array $message,
        $reply
    ): bool {

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

            Logger::write(
                'bot_greeting',
                [
                    'step' => 'START_MENU',
                    'user_id' => $user['id'] ?? null
                ]
            );

            (new BotMenuService())->main(
                $reply,
                $message,
                "👋 Welcome to SENDAM 🇳🇬"
            );

            return true;

        }

        /*
        |--------------------------------------------------------------------------
        | Greetings
        |--------------------------------------------------------------------------
        */

        if (
            preg_match(
                '/\b(hi|hello|hey|helo|good morning|good afternoon|good evening|morning|afternoon|evening|how are you|how are u)\b/i',
                $text
            )
        ) {

            $name = trim(
                $user['name'] ?? ''
            );

            if ($name === '') {

                $name = 'there';

            }

            Logger::write(
                'bot_greeting',
                [
                    'step' => 'GREETING',
                    'user_id' => $user['id'] ?? null,
                    'text' => $text
                ]
            );

            (new BotMenuService())->main(
                $reply,
                $message,
                "👋 Hello {$name}!"
            );

            return true;

        }

        return false;

    }

    /**
     * --------------------------------------------------------------------------
     * Is Greeting
     * --------------------------------------------------------------------------
     */

    public function isGreeting(
        string $text
    ): bool {

        return (bool) preg_match(
            '/\b(hi|hello|hey|helo|good morning|good afternoon|good evening|morning|afternoon|evening|how are you|how are u)\b/i',
            strtolower(trim($text))
        );

    }

    /**
     * --------------------------------------------------------------------------
     * Is Menu Request
     * --------------------------------------------------------------------------
     */

    public function isMenu(
        string $text
    ): bool {

        return in_array(
            strtolower(trim($text)),
            [
                '/start',
                'start',
                '/menu',
                'menu'
            ],
            true
        );

    }

}