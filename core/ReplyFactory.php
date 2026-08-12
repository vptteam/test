<?php

declare(strict_types=1);

namespace Core;

use InvalidArgumentException;
use Replies\TelegramReply;
use Replies\WhatsAppReply;


class ReplyFactory
{


    /**
     * Create reply service based on platform
     */
    public static function make(
        string $platform
    ): ReplyInterface {


        $platform = strtolower(
            trim($platform)
        );



        switch($platform){


            case 'telegram':


                Logger::write(

                    'reply_factory',

                    [

                        'platform'=>'telegram',

                        'reply'=>'TelegramReply'

                    ]

                );


                return new TelegramReply();







            case 'whatsapp':


                Logger::write(

                    'reply_factory',

                    [

                        'platform'=>'whatsapp',

                        'reply'=>'WhatsAppReply'

                    ]

                );


                return new WhatsAppReply();








            default:


                Logger::write(

                    'reply_factory_error',

                    [

                        'platform'=>$platform,

                        'error'=>'Unsupported platform'

                    ]

                );



                throw new InvalidArgumentException(

                    "Unsupported reply platform: {$platform}"

                );


        }


    }


}