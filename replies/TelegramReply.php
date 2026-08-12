<?php

declare(strict_types=1);

namespace Replies;

use Core\ReplyInterface;
use Core\Logger;

class TelegramReply implements ReplyInterface
{
    protected string $endpoint;


    public function __construct()
    {
        $this->endpoint =
            "https://api.telegram.org/bot"
            . TELEGRAM_BOT_TOKEN
            . "/";
    }


    /**
     *---------------------------------------------------------
     * Send Text
     *---------------------------------------------------------
     */
    public function text(
        string $chatId,
        string $text
    ): bool {

        return $this->request(

            'sendMessage',

            [

                'chat_id' => $chatId,

                'text' => $text,

                'parse_mode' => 'HTML'

            ]

        );

    }


    /**
     *---------------------------------------------------------
     * Send Photo
     *---------------------------------------------------------
     */
    public function photo(
        string $chatId,
        string $photo,
        string $caption = ''
    ): bool {

        return $this->request(

            'sendPhoto',

            [

                'chat_id' => $chatId,

                'photo' => $photo,

                'caption' => $caption,

                'parse_mode' => 'HTML'

            ]

        );

    }


    /**
     *---------------------------------------------------------
     * Send Document
     *---------------------------------------------------------
     */
    public function document(
        string $chatId,
        string $document,
        string $caption = ''
    ): bool {

        return $this->request(

            'sendDocument',

            [

                'chat_id' => $chatId,

                'document' => $document,

                'caption' => $caption

            ]

        );

    }


    /**
     *---------------------------------------------------------
     * Send Video
     *---------------------------------------------------------
     */
    public function video(
        string $chatId,
        string $video,
        string $caption = ''
    ): bool {

        return $this->request(

            'sendVideo',

            [

                'chat_id' => $chatId,

                'video' => $video,

                'caption' => $caption

            ]

        );

    }


    /**
     *---------------------------------------------------------
     * Typing Indicator
     *---------------------------------------------------------
     */
    public function typing(
        string $chatId
    ): bool {

        return $this->request(

            'sendChatAction',

            [

                'chat_id' => $chatId,

                'action' => 'typing'

            ]

        );

    }


    /**
     *---------------------------------------------------------
     * Delete Message
     *---------------------------------------------------------
     */
    public function delete(
        string $chatId,
        int $messageId
    ): bool {

        return $this->request(

            'deleteMessage',

            [

                'chat_id' => $chatId,

                'message_id' => $messageId

            ]

        );

    }


    /**
     *---------------------------------------------------------
     * Edit Message
     *---------------------------------------------------------
     */
    public function edit(
        string $chatId,
        int $messageId,
        string $text
    ): bool {

        return $this->request(

            'editMessageText',

            [

                'chat_id' => $chatId,

                'message_id' => $messageId,

                'text' => $text,

                'parse_mode' => 'HTML'

            ]

        );

    }


    /**
     *---------------------------------------------------------
     * Inline Buttons
     *---------------------------------------------------------
     */
    public function buttons(
        string $chatId,
        string $text,
        array $buttons
    ): bool {

        return $this->request(

            'sendMessage',

            [

                'chat_id' => $chatId,

                'text' => $text,

                'reply_markup' => json_encode(

                    [

                        'inline_keyboard' => $buttons

                    ]

                )

            ]

        );

    }


    /**
     *---------------------------------------------------------
     * Numbered Menu
     *---------------------------------------------------------
     */
    public function menu(
        string $chatId,
        string $title,
        array $items
    ): bool {


        $text = "<b>{$title}</b>\n\n";


        $number = 1;


        foreach ($items as $item) {

            $text .= $number++ 
                . ". "
                . $item
                . "\n";

        }


        return $this->text(

            $chatId,

            $text

        );

    }


    /**
     *---------------------------------------------------------
     * Core Telegram Request
     *---------------------------------------------------------
     */
    protected function request(
        string $method,
        array $payload
    ): bool {


        $ch = curl_init(

            $this->endpoint . $method

        );


        curl_setopt_array(

            $ch,

            [

                CURLOPT_RETURNTRANSFER => true,

                CURLOPT_POST => true,

                CURLOPT_POSTFIELDS => $payload,

                CURLOPT_TIMEOUT => 60,

                CURLOPT_CONNECTTIMEOUT => 15

            ]

        );


        $response = curl_exec($ch);


        $curlError = curl_error($ch);


        curl_close($ch);



        if ($curlError) {


            Logger::error(

                'telegram',

                [

                    'error' => $curlError,

                    'method' => $method

                ]

            );


            return false;

        }



        $data = json_decode(

            $response,

            true

        );



        Logger::write(

            'telegram_reply',

            $data ?? $response

        );



        if (

            !is_array($data)

            ||

            !isset($data['ok'])

        ) {


            Logger::error(

                'telegram',

                [

                    'error' => 'Invalid Telegram response',

                    'response' => $response

                ]

            );


            return false;

        }



        if ($data['ok'] !== true) {


            Logger::error(

                'telegram',

                [

                    'error' => $data['description'] ?? 'Unknown Telegram error',

                    'response' => $data

                ]

            );


            return false;

        }



        return true;

    }

}