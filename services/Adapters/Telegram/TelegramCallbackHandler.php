<?php

declare(strict_types=1);

namespace Services\Telegram;



use Core\Logger;
use Services\Adapters\Telegram\TelegramApi;
use Services\Telegram\TelegramActionRouter;
use Throwable;


class TelegramCallbackHandler
{

    protected TelegramApi $telegram;

    protected TelegramAdminAuth $auth;

    public function __construct()
    {

        $this->telegram = new TelegramApi();

        $this->auth = new TelegramAdminAuth();

    }

    /**
     * ---------------------------------------------------------
     * Handle Callback Query
     * ---------------------------------------------------------
     */
    public function handle(

        array $callback

    ): void
    {

        try {

            $telegramUserId =

                (int)($callback['from']['id'] ?? 0);

            /*
            |--------------------------------------------------------------------------
            | Authorize Admin
            |--------------------------------------------------------------------------
            */

            if (

                !$this->auth->isAdmin(

                    $telegramUserId

                )

            ) {

                $this->telegram->answerCallbackQuery(

                    $callback['id'],

                    'Unauthorized.',

                    true

                );

                return;

            }

            $data =

                trim(

                    (string)($callback['data'] ?? '')

                );

            Logger::write(

                'telegram_callback',

                [

                    'telegram_user' => $telegramUserId,

                    'callback'      => $data

                ]

            );

            if ($data === '') {

                return;

            }

            /*
            |--------------------------------------------------------------------------
            | callback format:
            |
            | escrow:paid:ESC12345
            | escrow:details:ESC12345
            | withdrawal:approve:WD123
            |--------------------------------------------------------------------------
            */

            $parts = explode(

                ':',

                $data

            );

            $module =

                strtolower(

                    $parts[0] ?? ''

                );

            $action =

                strtolower(

                    $parts[1] ?? ''

                );

            $reference =

                $parts[2] ?? '';

            $router = new TelegramActionRouter();

$result = $router->dispatch(

    $module,

    $action,

    $reference,

    $callback

);

if (!($result['success'] ?? false)) {

    $this->telegram->answerCallbackQuery(

        $callback['id'],

        $result['message']

        ??

        'Unable to process request.',

        true

    );

    return;

}

$this->telegram->answerCallbackQuery(

    $callback['id'],

    'Done.'

);

$this->telegram->editMessage(

    $callback['message']['chat']['id'],

    $callback['message']['message_id'],

    $result['message']

);

        }

        catch (Throwable $e) {

            Logger::write(

                'telegram_callback_error',

                [

                    'message' => $e->getMessage(),

                    'line' => $e->getLine(),

                    'file' => $e->getFile()

                ]

            );

        }

    }

      
}
