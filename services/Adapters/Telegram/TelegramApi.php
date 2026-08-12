<?php

declare(strict_types=1);

namespace Services\Adapters\Telegram;

use Core\Logger;
use Throwable;

class TelegramApi
{

    protected string $base;

    public function __construct()
    {

        $this->base =

            'https://api.telegram.org/bot'

            .

            TELEGRAM_BOT_TOKEN

            .

            '/';

    }

    /**
     * ---------------------------------------------------------
     * Telegram Request
     * ---------------------------------------------------------
     */
    public function request(

        string $method,

        array $payload = []

    ): array
    {

        try {

            $ch = curl_init(

                $this->base.$method

            );

            curl_setopt_array(

                $ch,

                [

                    CURLOPT_RETURNTRANSFER => true,

                    CURLOPT_POST => true,

                    CURLOPT_POSTFIELDS => $payload,

                    CURLOPT_CONNECTTIMEOUT => 10,

                    CURLOPT_TIMEOUT => 30,
                    
                    CURLOPT_SSL_VERIFYPEER => true,

                    CURLOPT_SSL_VERIFYHOST => 2,

                ]

            );

            $response = curl_exec($ch);

            $http = curl_getinfo(

                $ch,

                CURLINFO_HTTP_CODE

            );

            $error = curl_error($ch);

            curl_close($ch);

            Logger::write(

                'telegram_api',

                [

                    'method'  => $method,

                    'http'    => $http,

                    'payload' => $payload,

                    'response'=> $response,

                    'error'   => $error

                ]

            );

            if ($error) {

                return [

                    'ok'    => false,

                    'error' => $error

                ];

            }

            $decoded = json_decode(

                $response,

                true

            );
            
            if (

    isset($decoded['ok'])

    &&

    $decoded['ok'] === false

) {

    Logger::write(

        'telegram_api',

        [

            'step'        => 'TELEGRAM_ERROR',

            'description' =>

                $decoded['description']

                ??

                null,

            'error_code'  =>

                $decoded['error_code']

                ??

                null

        ]

    );

}

            if (!is_array($decoded)) {

                return [

                    'ok' => false,

                    'error' => 'Invalid Telegram response.'

                ];

            }

            return $decoded;

        }

        catch (Throwable $e) {

            Logger::write(

                'telegram_api_error',

                [

                    'message' => $e->getMessage(),

                    'line'    => $e->getLine(),

                    'file'    => $e->getFile()

                ]

            );

            return [

                'ok' => false,

                'error' => $e->getMessage()

            ];

        }

    }

        /**
     * ---------------------------------------------------------
     * Send Message
     * ---------------------------------------------------------
     */
    public function sendMessage(

        int|string $chatId,

        string $text,

        array $buttons = [],

        array $options = []

    ): array
    {

        $payload = [

            'chat_id' => $chatId,

            'text' => $text,

            'parse_mode' =>

                $options['parse_mode']

                ??

                'Markdown',

            'disable_web_page_preview' =>

                $options['disable_web_page_preview']

                ??

                true,

            'disable_notification' =>

                $options['disable_notification']

                ??

                false

        ];

        /*
        |--------------------------------------------------------------------------
        | Reply To Message
        |--------------------------------------------------------------------------
        */

        if (

            !empty($options['reply_to_message_id'])

        ) {

            $payload['reply_to_message_id'] =

                $options['reply_to_message_id'];

        }

        /*
        |--------------------------------------------------------------------------
        | Protect Content
        |--------------------------------------------------------------------------
        */

        if (

            isset($options['protect_content'])

        ) {

            $payload['protect_content'] =

                (bool)$options['protect_content'];

        }

        /*
        |--------------------------------------------------------------------------
        | Inline Keyboard
        |--------------------------------------------------------------------------
        */

        if (!empty($buttons)) {

            $payload['reply_markup'] = json_encode([

                'inline_keyboard' => $buttons

            ]);

        }

        return $this->request(

            'sendMessage',

            $payload

        );

    }

    /**
     * ---------------------------------------------------------
     * Send Chat Action
     * ---------------------------------------------------------
     */
    public function sendTyping(

        int|string $chatId,

        string $action = 'typing'

    ): array
    {

        return $this->request(

            'sendChatAction',

            [

                'chat_id' => $chatId,

                'action' => $action

            ]

        );

    }

       /**
     * ---------------------------------------------------------
     * Send Photo
     * ---------------------------------------------------------
     */
    public function sendPhoto(

        int|string $chatId,

        string $photo,

        string $caption = '',

        array $buttons = [],

        array $options = []

    ): array
    {

        $payload = [

            'chat_id' => $chatId,

            'photo'   => $photo,

            'caption' => $caption,

            'parse_mode' =>

                $options['parse_mode']

                ??

                'Markdown'

        ];

        if (!empty($buttons)) {

            $payload['reply_markup'] = json_encode([

                'inline_keyboard' => $buttons

            ]);

        }

        return $this->request(

            'sendPhoto',

            $payload

        );

    }

    /**
     * ---------------------------------------------------------
     * Send Document
     * ---------------------------------------------------------
     */
    public function sendDocument(

        int|string $chatId,

        string $document,

        string $caption = '',

        array $buttons = [],

        array $options = []

    ): array
    {

        $payload = [

            'chat_id' => $chatId,

            'document' => $document,

            'caption' => $caption,

            'parse_mode' =>

                $options['parse_mode']

                ??

                'Markdown'

        ];

        if (!empty($buttons)) {

            $payload['reply_markup'] = json_encode([

                'inline_keyboard' => $buttons

            ]);

        }

        return $this->request(

            'sendDocument',

            $payload

        );

    }

    /**
     * ---------------------------------------------------------
     * Edit Message
     * ---------------------------------------------------------
     */
    public function editMessage(

    int|string $chatId,

    int $messageId,

    string $text,

    array $buttons = [],

    array $options = []

): array
    {

        $payload = [

            'chat_id' => $chatId,

            'message_id' => $messageId,

            'text' => $text,

            'parse_mode' =>

$options['parse_mode']

??

'Markdown'

        ];

        if (!empty($buttons)) {

            $payload['reply_markup'] = json_encode([

                'inline_keyboard' => $buttons

            ]);

        }

        return $this->request(

            'editMessageText',

            $payload

        );

    }

        /**
     * ---------------------------------------------------------
     * Answer Callback Query
     * ---------------------------------------------------------
     */
    public function answerCallbackQuery(

        string $callbackQueryId,

        string $text = '',

        bool $showAlert = false

    ): array
    {

        return $this->request(

            'answerCallbackQuery',

            [

                'callback_query_id' => $callbackQueryId,

                'text'              => $text,

                'show_alert'        => $showAlert

            ]

        );

    }

    /**
     * ---------------------------------------------------------
     * Delete Message
     * ---------------------------------------------------------
     */
    public function deleteMessage(

        int|string $chatId,

        int $messageId

    ): array
    {

        return $this->request(

            'deleteMessage',

            [

                'chat_id'    => $chatId,

                'message_id' => $messageId

            ]

        );

    }

    /**
     * ---------------------------------------------------------
     * Get Telegram File
     * ---------------------------------------------------------
     */
    public function getFile(

        string $fileId

    ): array
    {

        return $this->request(

            'getFile',

            [

                'file_id' => $fileId

            ]

        );

    }

    /**
     * ---------------------------------------------------------
     * Download Telegram File
     * ---------------------------------------------------------
     */
    public function downloadFile(

        string $filePath

    ): ?string
    {

        try {

            $url =

                'https://api.telegram.org/file/bot'

                .

                TELEGRAM_BOT_TOKEN

                .

                '/'

                .

                ltrim(

                    $filePath,

                    '/'

                );

            $contents = @file_get_contents($url);

            if ($contents === false) {

                Logger::write(

                    'telegram_api',

                    [

                        'step' => 'DOWNLOAD_FAILED',

                        'file' => $filePath

                    ]

                );

                return null;

            }

            return $contents;

        }

        catch (Throwable $e) {

            Logger::write(

                'telegram_api_error',

                [

                    'step'    => 'DOWNLOAD_EXCEPTION',

                    'message' => $e->getMessage(),

                    'line'    => $e->getLine()

                ]

            );

            return null;

        }

    }

}


