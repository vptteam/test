<?php

declare(strict_types=1);

namespace Listeners\WhatsApp;


use Modules\BotEngine;

use Core\Logger;

use Models\User;

use Throwable;



class WhatsAppListener
{


    /**
     * Handle Twilio WhatsApp webhook
     */
    public function handle(): void
    {


        try {


            /*
            |--------------------------------------------------------------------------
            | Log Incoming Request
            |--------------------------------------------------------------------------
            */


            Logger::write(

                'twilio_whatsapp_webhook_hit',

                [

                    'time'=>date('Y-m-d H:i:s'),

                    'method'=>$_SERVER['REQUEST_METHOD'] ?? '',

                    'post'=>$_POST

                ]

            );






            /*
            |--------------------------------------------------------------------------
            | Twilio sends POST only
            |--------------------------------------------------------------------------
            */


            if (

                ($_SERVER['REQUEST_METHOD'] ?? '')

                !==

                'POST'

            ) {


                http_response_code(200);

                echo "OK";

                return;


            }








            /*
            |--------------------------------------------------------------------------
            | Extract WhatsApp Message
            |--------------------------------------------------------------------------
            */


            $message =
                $this->extractMessage();





            if(!$message){


                Logger::write(

                    'twilio_no_message',

                    [

                        'post'=>$_POST

                    ]

                );


                http_response_code(200);

                echo "OK";

                return;


            }







            /*
            |--------------------------------------------------------------------------
            | Process Message
            |--------------------------------------------------------------------------
            */


            $this->processMessage(

                $message

            );






            http_response_code(200);

            echo "OK";




        }

        catch(Throwable $e){



            Logger::write(

                'twilio_whatsapp_listener_exception',

                [

                    'message'=>$e->getMessage(),

                    'file'=>$e->getFile(),

                    'line'=>$e->getLine(),

                    'trace'=>$e->getTraceAsString()

                ]

            );



            http_response_code(200);

            echo "OK";


        }


    }









    /**
     * Convert Twilio payload
     */
    protected function extractMessage(): ?array
    {


        try {


            $from =

                $_POST['From']

                ??

                '';





            if($from === ''){


                return null;


            }






            /*
            |--------------------------------------------------------------------------
            | Remove WhatsApp prefix
            |--------------------------------------------------------------------------
            */


            $phone = str_replace(

                'whatsapp:+',

                '',

                $from

            );








            return [


                'phone'=>
                    $phone,



                'platform_id'=>
                    $phone,



                'name'=>

                    $_POST['ProfileName']

                    ??

                    '',




                'message_id'=>

                    $_POST['MessageSid']

                    ??

                    null,





                'type'=>
                    'text',





                'text'=>

                    trim(

                        $_POST['Body']

                        ??

                        ''

                    ),





                'media'=>

                    $this->extractMedia()



            ];





        }

        catch(Throwable $e){



            Logger::write(

                'twilio_extract_error',

                [

                    'message'=>$e->getMessage(),

                    'post'=>$_POST

                ]

            );


            return null;


        }


    }
        /**
     * Process Message
     */
    protected function processMessage(
        array $message
    ): void {


        Logger::write(

            'twilio_message_processing',

            $message

        );







        /*
        |--------------------------------------------------------------------------
        | Load Real Database User
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | $user['id'] MUST be users.id
        |
        | NOT WhatsApp phone number
        |
        |--------------------------------------------------------------------------
        */


        $userModel =
            new User();




        $dbUser =
            $userModel->findOrCreatePlatformUser(

                'whatsapp',


                (string)$message['platform_id'],


                $message['phone'] ?? null,


                $message['name'] ?? null

            );








        $user = [


            /*
            |--------------------------------------------------------------------------
            | Internal Database User ID
            |--------------------------------------------------------------------------
            */


            'id'=>

                (int)$dbUser['id'],




            'platform'=>

                'whatsapp',





            /*
            |--------------------------------------------------------------------------
            | External WhatsApp Identity
            |--------------------------------------------------------------------------
            */


            'platform_id'=>

                (string)$message['platform_id'],





            'phone'=>

                $message['phone'],





            'name'=>

                $dbUser['name']
                ??

                ''

        ];









        /*
        |--------------------------------------------------------------------------
        | Build Internal Bot Message
        |--------------------------------------------------------------------------
        */


        $internalMessage = [



            'platform'=>

                'whatsapp',





            'phone'=>

                $message['phone'],





            'type'=>

                $message['type'],





            'text'=>

                $message['text'],





            /*
            Keep Twilio payload
            for debugging/media
            */


            'raw'=>

                $_POST,





            'media'=>

                $message['media'],





            'message_id'=>

                $message['message_id']



        ];









        Logger::write(

            'before_twilio_bot_engine',

            [

                'user'=>$user,

                'message'=>$internalMessage

            ]

        );









        try {


            /*
            |--------------------------------------------------------------------------
            | Run Bot Engine
            |--------------------------------------------------------------------------
            */


            $bot =

                new BotEngine();





            $bot->process(

                $user,

                $internalMessage

            );








            Logger::write(

                'after_twilio_bot_engine',

                [

                    'status'=>'completed',

                    'user_id'=>$user['id']

                ]

            );




        }

        catch(Throwable $e){



            Logger::write(

                'twilio_bot_engine_exception',

                [

                    'message'=>$e->getMessage(),

                    'file'=>$e->getFile(),

                    'line'=>$e->getLine(),

                    'trace'=>$e->getTraceAsString()

                ]

            );



        }


    }
        /**
     * Extract Twilio WhatsApp Media
     */
    protected function extractMedia(): ?array
    {


        try {


            $count =

                (int)(

                    $_POST['NumMedia']

                    ??

                    0

                );





            if($count <= 0){


                return null;


            }








            $items = [];





            for(
                $i = 0;
                $i < $count;
                $i++
            ){



                $items[] = [



                    'url'=>

                        $_POST["MediaUrl{$i}"]

                        ??

                        null,





                    'content_type'=>

                        $_POST["MediaContentType{$i}"]

                        ??

                        null



                ];



            }







            return [



                'count'=>

                    $count,





                'items'=>

                    $items



            ];





        }

        catch(Throwable $e){



            Logger::write(

                'twilio_media_extract_error',

                [

                    'message'=>$e->getMessage(),

                    'post'=>$_POST

                ]

            );



            return null;


        }


    }


}