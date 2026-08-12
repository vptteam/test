<?php

declare(strict_types=1);

namespace Replies;

use Core\ReplyInterface;
use Core\Logger;
use Services\Adapters\WhatsApp\ProviderFactory;
use Services\Adapters\WhatsApp\WhatsAppProviderInterface;
use Throwable;


class WhatsAppReply implements ReplyInterface
{

    protected WhatsAppProviderInterface $provider;



    /**
     * Constructor
     */
    public function __construct()
    {

        $this->provider = ProviderFactory::make();


        Logger::write(
            'whatsapp_reply_debug',
            [

                'step'=>'provider_loaded',

                'provider'=>get_class(
                    $this->provider
                )

            ]
        );

    }







    /**
     * Send Text Message
     */
    public function text(
    string $recipient,
    string $message
): bool {

    try {


        $result = $this->provider->text(

            $recipient,

            $message

        );



        if(!$result){


            Logger::write(

                'whatsapp_send_failed',

                [

                    'action'=>'text',

                    'recipient'=>$recipient,

                    'message'=>$message

                ]

            );


        }



        return $result;



    } catch(Throwable $e){


        return $this->error(

            'text',

            $e

        );

    }

}









    /**
     * Send Image
     */
    public function photo(
        string $recipient,
        string $photo,
        string $caption=''
    ): bool {

        try {


            return $this->provider->image(

                $recipient,

                $photo,

                $caption

            );


        } catch(Throwable $e){


            return $this->error(
                'photo',
                $e
            );

        }

    }









    /**
     * Send Document
     */
    public function document(
        string $recipient,
        string $document,
        string $caption=''
    ): bool {

        try {


            return $this->provider->document(

                $recipient,

                $document,

                $caption

            );


        } catch(Throwable $e){


            return $this->error(
                'document',
                $e
            );

        }

    }









    /**
     * Send Video
     *
     * Temporary fallback.
     * Provider interface will be extended later.
     */
    public function video(
        string $recipient,
        string $video,
        string $caption=''
    ): bool {

        try {


            return $this->provider->document(

                $recipient,

                $video,

                $caption

            );


        } catch(Throwable $e){


            return $this->error(
                'video',
                $e
            );

        }

    }









    /**
     * Send Buttons
     */
    public function buttons(
        string $recipient,
        string $message,
        array $buttons
    ): bool {

        try {


            return $this->provider->buttons(

                $recipient,

                $message,

                $buttons

            );


        } catch(Throwable $e){


            return $this->error(
                'buttons',
                $e
            );

        }

    }









    /**
     * Send Menu
     */
    public function menu(
        string $recipient,
        string $title,
        array $items
    ): bool {

        try {


            return $this->provider->menu(

                $recipient,

                $title,

                $items

            );


        } catch(Throwable $e){


            return $this->error(
                'menu',
                $e
            );

        }

    }









    /**
     * Typing Indicator
     *
     * WhatsApp Cloud API currently
     * does not support typing indicator.
     */
    public function typing(
        string $recipient
    ): bool {


        Logger::write(

            'whatsapp_reply_debug',

            [

                'step'=>'typing_not_supported',

                'recipient'=>$recipient

            ]

        );


        return true;

    }









    /**
     * Error Handler
     */
    protected function error(
        string $action,
        Throwable $e
    ): bool {


        Logger::write(

            'whatsapp_reply_error',

            [

                'action'=>$action,

                'message'=>$e->getMessage(),

                'file'=>$e->getFile(),

                'line'=>$e->getLine()

            ]

        );


        return false;

    }


}