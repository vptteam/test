<?php

declare(strict_types=1);

namespace Listeners\Telegram;

use Modules\BotEngine;
use Core\Logger;
use Services\Telegram\TelegramCallbackHandler;

class TelegramListener
{
    public function handle(): void
    {

        /*
        |--------------------------------------------------------------------------
        | Webhook Debug
        |--------------------------------------------------------------------------
        */

        Logger::write(

            'telegram_hit',

            [

                'time'   => date('Y-m-d H:i:s'),

                'method' => $_SERVER['REQUEST_METHOD'] ?? '',

                'uri'    => $_SERVER['REQUEST_URI'] ?? '',

                'headers'=> getallheaders()

            ]

        );

        /*
        |--------------------------------------------------------------------------
        | Verify Telegram Secret
        |--------------------------------------------------------------------------
        */

        if (

            defined('TELEGRAM_WEBHOOK_SECRET')

            &&

            TELEGRAM_WEBHOOK_SECRET !== ''

        ) {

            $secret =

                $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']

                ??

                '';

            if ($secret !== TELEGRAM_WEBHOOK_SECRET) {

                Logger::write(

                    'telegram_error',

                    [

                        'error' => 'INVALID_WEBHOOK_SECRET'

                    ]

                );

                http_response_code(403);

                echo 'Invalid secret';

                return;

            }

        }

        /*
        |--------------------------------------------------------------------------
        | Receive Update
        |--------------------------------------------------------------------------
        */

        $raw = file_get_contents(

            'php://input'

        );

        $update = json_decode(

            $raw,

            true

        );

        if (!is_array($update)) {

            Logger::write(

                'telegram_error',

                [

                    'error' => 'INVALID_PAYLOAD',

                    'raw'   => $raw

                ]

            );

            http_response_code(200);

            echo 'OK';

            return;

        }

        Logger::write(

            'telegram_listener',

            $update

        );

        /*
        |--------------------------------------------------------------------------
        | CALLBACK QUERY
        |--------------------------------------------------------------------------
        |
        | Inline buttons DO NOT go through BotEngine.
        | They are handled directly.
        |
        */

        if (!empty($update['callback_query'])) {

            Logger::write(

                'telegram_callback_received',

                [

                    'data' =>

                        $update['callback_query']['data']

                        ??

                        '',

                    'from' =>

                        $update['callback_query']['from']['id']

                        ??

                        null

                ]

            );

            try {

                $handler = new TelegramCallbackHandler();

                $handler->handle(

                    $update['callback_query']

                );

            }

            catch (\Throwable $e) {

                Logger::write(

                    'telegram_callback_exception',

                    [

                        'message' => $e->getMessage(),

                        'file' => $e->getFile(),

                        'line' => $e->getLine()

                    ]

                );

            }

            http_response_code(200);

            echo 'OK';

            return;

        }

        /*
        |--------------------------------------------------------------------------
        | Require Telegram Message
        |--------------------------------------------------------------------------
        */

        if (!isset($update['message'])) {

            http_response_code(200);

            echo 'OK';

            return;

        }

        $message =

            $update['message'];
                    /*
        |--------------------------------------------------------------------------
        | Telegram IDs
        |--------------------------------------------------------------------------
        */

        $telegramId =

            (int)(

                $message['from']['id']

                ??

                0

            );

        $chatId =

            (string)(

                $message['chat']['id']

                ??

                ''

            );

        if (

            $telegramId <= 0

            ||

            $chatId === ''

        ) {

            Logger::write(

                'telegram_error',

                [

                    'error' => 'INVALID_MESSAGE',

                    'message' => $message

                ]

            );

            http_response_code(200);

            echo 'OK';

            return;

        }

        /*
        |--------------------------------------------------------------------------
        | Detect Message Type
        |--------------------------------------------------------------------------
        */

        $type = 'text';

        if (isset($message['photo'])) {

            $type = 'photo';

        }

        elseif (isset($message['document'])) {

            $type = 'document';

        }

        elseif (isset($message['video'])) {

            $type = 'video';

        }

        elseif (isset($message['voice'])) {

            $type = 'voice';

        }

        elseif (isset($message['audio'])) {

            $type = 'audio';

        }

        elseif (isset($message['location'])) {

            $type = 'location';

        }

        elseif (isset($message['contact'])) {

            $type = 'contact';

        }

        /*
        |--------------------------------------------------------------------------
        | Find / Create Platform User
        |--------------------------------------------------------------------------
        */

        $userModel = new \Models\User();

        $dbUser =

            $userModel->findOrCreatePlatformUser(

                'telegram',

                (string)$telegramId,

                $chatId,

                trim(

                    ($message['from']['first_name'] ?? '')

                    .

                    ' '

                    .

                    ($message['from']['last_name'] ?? '')

                )

            );

        /*
        |--------------------------------------------------------------------------
        | Internal User Object
        |--------------------------------------------------------------------------
        */

        $user = [

            'id' =>

                (int)$dbUser['id'],

            'platform' =>

                'telegram',

            'platform_id' =>

                $telegramId,

            'phone' =>

                $chatId,

            'name' =>

                $dbUser['name']

                ??

                '',

            'username' =>

                $message['from']['username']

                ??

                ''

        ];

        /*
        |--------------------------------------------------------------------------
        | Internal Payload
        |--------------------------------------------------------------------------
        */

        $payload = [

            'platform' =>

                'telegram',

            'phone' =>

                $chatId,

            'type' =>

                $type,

            'text' =>

                trim(

                    $message['text']

                    ??

                    ''

                ),

            /*
            |--------------------------------------------------------------------------
            | Preserve full Telegram payload
            |--------------------------------------------------------------------------
            */

            'raw' =>

                $update

        ];

        Logger::write(

            'telegram_before_bot',

            [

                'user' => $user,

                'payload' => $payload

            ]

        );
        /*
        |--------------------------------------------------------------------------
        | Run Bot Engine
        |--------------------------------------------------------------------------
        */

        try {

            Logger::write(
                'telegram_listener',
                [
                    'step' => 'BOT_ENGINE_START',
                    'user_id' => $user['id'],
                    'platform_id' => $user['platform_id']
                ]
            );

            $bot = new BotEngine();

            $bot->process(
                $user,
                $payload
            );

            Logger::write(
                'telegram_listener',
                [
                    'step' => 'BOT_ENGINE_COMPLETED',
                    'user_id' => $user['id']
                ]
            );

        } catch (\Throwable $e) {

            Logger::write(
                'telegram_listener_error',
                [
                    'step'    => 'BOT_ENGINE_EXCEPTION',
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'trace'   => $e->getTraceAsString()
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Telegram Response
        |--------------------------------------------------------------------------
        */

        http_response_code(200);

        echo 'OK';

        return;

    }

}