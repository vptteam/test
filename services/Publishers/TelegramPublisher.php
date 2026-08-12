<?php

declare(strict_types=1);

namespace Services\Publishers;

use Replies\TelegramReply;
use Core\Logger;
use Throwable;


class TelegramPublisher implements PublisherInterface
{


    public function publish(
        array $listing
    ): bool {


        Logger::write(
            'telegram_publisher_debug',
            [
                'step'=>'START',
                'listing_id'=>$listing['id'] ?? null,
                'listing'=>$listing
            ]
        );



        try {


            /*
            |--------------------------------------------------------------------------
            | Check Constants
            |--------------------------------------------------------------------------
            */


            Logger::write(
                'telegram_publisher_debug',
                [
                    'step'=>'CHECK_CONSTANTS',

                    'telegram_channel'=>defined('TELEGRAM_CHANNEL_ID')
                        ? TELEGRAM_CHANNEL_ID
                        : 'MISSING',

                    'app_url'=>defined('APP_URL')
                        ? APP_URL
                        : 'MISSING'
                ]
            );





            /*
            |--------------------------------------------------------------------------
            | Create Reply Service
            |--------------------------------------------------------------------------
            */


            $reply = new TelegramReply();


            Logger::write(
                'telegram_publisher_debug',
                [
                    'step'=>'TELEGRAM_REPLY_CREATED',
                    'class'=>get_class($reply)
                ]
            );






            /*
            |--------------------------------------------------------------------------
            | Build Caption
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
                
                "🛡️ *Pay with Escrow*\n\n".
               "If you are scared, pay securely through SENDAM Escrow.\n\n".
                

"━━━━━━━━━━━━━━\n\n".

                "⚠️ Choose to trade directly?\n".

                "• Meet in a safe public location.\n".

                "• Inspect items before payment.\n".

                "• Do not send money before verification.\n".

                "• Avoid suspicious requests.\n\n".

                "Need Help? Call 08030981624-08123370000";




            Logger::write(
                'telegram_publisher_debug',
                [
                    'step'=>'CAPTION_READY',
                    'caption'=>$caption
                ]
            );






            /*
            |--------------------------------------------------------------------------
            | Get Photos From Listing
            |--------------------------------------------------------------------------
            */


            $images = $listing['photos'] ?? [];



            Logger::write(
                'telegram_publisher_debug',
                [
                    'step'=>'PHOTOS_FROM_LISTING',
                    'count'=>count($images),
                    'photos'=>$images
                ]
            );






            /*
            |--------------------------------------------------------------------------
            | Send Photos
            |--------------------------------------------------------------------------
            */


            if(count($images) > 0){



                $sent = 0;



                foreach ($images as $index => $image) {

    if (empty($image['filepath'])) {

        Logger::write(
            'telegram_publisher_debug',
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


                    Logger::write(
                        'telegram_publisher_debug',
                        [
                            'step'=>'IMAGE_URL_CREATED',
                            'url'=>$url
                        ]
                    );







                    $result = $reply->photo(
    TELEGRAM_CHANNEL_ID,
    $url,
    $caption
);


if (!$result) {

    Logger::write(
        'telegram_publisher_error',
        [
            'step'=>'PHOTO_FAILED',
            'channel'=>TELEGRAM_CHANNEL_ID,
            'url'=>$url,
            'caption'=>$caption
        ]
    );

    return false;

}





                   Logger::write(
    'telegram_publisher_debug',
    [
        'step' => 'TELEGRAM_PHOTO_RESPONSE',
        'response' => $result,
        'type' => gettype($result)
    ]
);

if ($result === false) {

    Logger::write(
        'telegram_publisher_error',
        [
            'step' => 'PHOTO_SEND_FAILED',
            'index' => $index
        ]
    );

    return false;

}





                    $sent++;


                    /*
                    Only first photo gets caption
                    */

                    $caption='';



                }






                Logger::write(
                    'telegram_publisher_debug',
                    [
                        'step'=>'ALL_IMAGES_SENT',
                        'sent'=>$sent
                    ]
                );



            }

            else {



                Logger::write(
                    'telegram_publisher_debug',
                    [
                        'step'=>'NO_IMAGES_SEND_TEXT'
                    ]
                );



                $response = $reply->text(

                    TELEGRAM_CHANNEL_ID,

                    $caption

                );




                Logger::write(
                    'telegram_publisher_debug',
                    [
                        'step'=>'TEXT_RESPONSE',
                        'response'=>$response
                    ]
                );


                if($response === false){

                    return false;

                }



            }







            Logger::write(
                'telegram_publisher_debug',
                [
                    'step'=>'FINISHED_SUCCESS'
                ]
            );



            return true;



        }

        catch(Throwable $e){



            Logger::write(
                'telegram_publisher_error',
                [

                    'step'=>'EXCEPTION',

                    'message'=>$e->getMessage(),

                    'line'=>$e->getLine(),

                    'file'=>$e->getFile(),

                    'trace'=>$e->getTraceAsString()

                ]
            );



            return false;


        }



    }







    protected function url(
        string $path
    ): string {



        Logger::write(
            'telegram_publisher_debug',
            [
                'step'=>'URL_FUNCTION_START',
                'original'=>$path
            ]
        );




        $clean = str_replace(

            BASE_PATH,

            '',

            $path

        );



        $url = rtrim(APP_URL,'/')
            .
            '/'
            .
            ltrim($clean,'/');




        Logger::write(
            'telegram_publisher_debug',
            [
                'step'=>'URL_CREATED',
                'url'=>$url
            ]
        );



        return $url;



    }


}