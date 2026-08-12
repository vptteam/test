<?php

declare(strict_types=1);

namespace Services\Publishers;

use Models\Media;
use Replies\WhatsAppReply;
use Core\Logger;
use Throwable;


class WhatsAppPublisher implements PublisherInterface
{


    /**
     * Publish listing to WhatsApp
     */
    public function publish(

        array $listing

    ): bool {


        Logger::write(

            'whatsapp_publisher_debug',

            [

                'step'=>'START',

                'listing'=>$listing

            ]

        );



        try {



            /*
            |--------------------------------------------------------------------------
            | Create WhatsApp Reply Provider
            |--------------------------------------------------------------------------
            */


            $reply = new WhatsAppReply();



            Logger::write(

                'whatsapp_publisher_debug',

                [

                    'step'=>'WHATSAPP_REPLY_READY'

                ]

            );







            /*
            |--------------------------------------------------------------------------
            | Destination Number
            |--------------------------------------------------------------------------
            */


            $recipient = SENDAM_ADMIN_WHATSAPP ?? null;



            if(!$recipient){


                Logger::write(

                    'whatsapp_publisher_error',

                    [

                        'step'=>'MISSING_RECIPIENT'

                    ]

                );


                return false;


            }







            /*
            |--------------------------------------------------------------------------
            | Create Listing Caption
            |--------------------------------------------------------------------------
            */


            $caption =

                "🛍️ SENDAM Marketplace 🇳🇬\n\n".

                "🏷️ Title:\n".
                ($listing['title'] ?? 'Not provided').
                "\n\n".

                "💰 Price:\n₦".
                number_format(
                    (float)($listing['price'] ?? 0)
                ).
                "\n\n".

                "📍 Location:\n".
                ($listing['location'] ?? 'Not provided').
                "\n\n".

                "📝 Description:\n".
                ($listing['description'] ?? 'Not provided').
                "\n\n".
                
                "📦 Listing Reference:\n".
($listing['reference'] ?? 'N/A').
"\n\n".

"🛡️ Pay with SENDAM Escrow\n".
"If you're unsure, pay securely through SENDAM Escrow.\n\n".



                "━━━━━━━━━━━━━━\n\n".

                "⚠️ Safety Advice:\n".

                "• Meet in a safe public location.\n".

                "• Inspect items before payment.\n".

                "• Do not send money before verification.\n".

                "• Avoid suspicious requests.\n\n".

                "🚀 Powered by SENDAM";





            Logger::write(

                'whatsapp_publisher_debug',

                [

                    'step'=>'CAPTION_CREATED',

                    'caption'=>$caption

                ]

            );









            /*
            |--------------------------------------------------------------------------
            | Load Listing Images
            |--------------------------------------------------------------------------
            */


            $media = new Media();



            $images = $media->images(

                'marketplace',

                (int)($listing['id'] ?? 0)

            );




            Logger::write(

                'whatsapp_publisher_debug',

                [

                    'step'=>'IMAGES_LOADED',

                    'count'=>count($images),

                    'images'=>$images

                ]

            );









            $sent = false;







            /*
            |--------------------------------------------------------------------------
            | Send Images
            |--------------------------------------------------------------------------
            */


            if(!empty($images)){



               foreach ($images as $index => $image) {

    if (empty($image['filepath'])) {

        Logger::write(
            'whatsapp_publisher_debug',
            [
                'step' => 'SKIP_EMPTY_FILEPATH',
                'index' => $index
            ]
        );

        continue;
    }

    $url = $this->url(
        $image['filepath']
    );



                    $url = $this->url(

                        $image['filepath'] ?? ''

                    );





                    Logger::write(

                        'whatsapp_publisher_debug',

                        [

                            'step'=>'SENDING_PHOTO',

                            'index'=>$index,

                            'url'=>$url

                        ]

                    );







                    /*
                    |
                    | First image gets caption
                    |
                    */


                    $messageCaption =

                        ($index === 0)

                        ?

                        $caption

                        :

                        '';





                    $result = $reply->photo(

                        $recipient,

                        $url,

                        $messageCaption

                    );






                   Logger::write(
    'whatsapp_publisher_debug',
    [
        'step' => 'PHOTO_RESPONSE',
        'index' => $index,
        'result' => $result,
        'type' => gettype($result)
    ]
);




                    if ($result === false) {

    Logger::write(
        'whatsapp_publisher_error',
        [
            'step' => 'PHOTO_SEND_FAILED',
            'index' => $index,
            'url' => $url
        ]
    );

    return false;
}

$sent = true;


                }





            }

            else {



                /*
                |--------------------------------------------------------------------------
                | No Images - Send Text Only
                |--------------------------------------------------------------------------
                */


                Logger::write(

                    'whatsapp_publisher_debug',

                    [

                        'step'=>'TEXT_ONLY'

                    ]

                );



                $sent = $reply->text(

                    $recipient,

                    $caption

                );


            }









            Logger::write(

                'whatsapp_publisher_debug',

                [

                    'step'=>'COMPLETE',

                    'sent'=>$sent

                ]

            );



            return $sent;



        }


        catch(Throwable $e){



            Logger::write(

                'whatsapp_publisher_error',

                [

                    'step'=>'FAILED',

                    'message'=>$e->getMessage(),

                    'file'=>$e->getFile(),

                    'line'=>$e->getLine()

                ]

            );



            return false;


        }



    }









    /**
     * Convert local storage path to public URL
     */
    protected function url(

        string $path

    ): string {


        Logger::write(

            'whatsapp_publisher_debug',

            [

                'step'=>'URL_BUILD',

                'path'=>$path

            ]

        );



        if(empty($path)){


            return '';

        }





        $path = str_replace(

            BASE_PATH,

            '',

            $path

        );





        $url =

            rtrim(APP_URL,'/')

            .

            '/'

            .

            ltrim($path,'/');






        Logger::write(

            'whatsapp_publisher_debug',

            [

                'step'=>'URL_READY',

                'url'=>$url

            ]

        );



        return $url;



    }



}