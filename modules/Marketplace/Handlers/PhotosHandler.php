<?php

declare(strict_types=1);

namespace Modules\Marketplace\Handlers;

use Core\ReplyInterface;
use Services\MediaDownloader;
use Core\Logger;

class PhotosHandler
{


    /**
     * Ask user for photos
     */
    public function ask(
        ReplyInterface $reply,
        string $phone,
        array $data = []
    ): void {


        Logger::write(

            'photos_handler_debug',

            [

                'step'=>'ask_start',

                'phone'=>$phone,

                'existing_data'=>$data

            ]

        );



        $reply->text(

    $phone,

    "📸 Upload product photos\n\n"

    .

    "Send clear pictures of the item you want to sell.\n\n"

    .

    "Tips:\n"

    .

    "✅ Use good lighting\n"

    .

    "✅ Show the actual condition of the item\n"

    .

    "✅ Maximum 5 photos\n\n"

    .

    "When finished, type:\n"

    .

    "DONE"

);



        Logger::write(

            'photos_handler_debug',

            [

                'step'=>'ask_complete'

            ]

        );


    }





    /**
     * Validate incoming message
     */
    public function validate(
        array $message
    ): bool {


        Logger::write(

            'photos_handler_debug',

            [

                'step'=>'validate_start',

                'type'=>$message['type'] ?? null,

                'text'=>$message['text'] ?? null

            ]

        );



        $text = strtolower(

            trim(

                $message['text'] ?? ''

            )

        );



        /*
        |--------------------------------------------------------------------------
        | User finished photos
        |--------------------------------------------------------------------------
        */


        if ($text === 'done') {


            Logger::write(

                'photos_handler_debug',

                [

                    'step'=>'validate_done'

                ]

            );


            return true;

        }




        $valid = (

            ($message['type'] ?? '')

            ===

            'photo'

        );




        Logger::write(

            'photos_handler_debug',

            [

                'step'=>'validate_result',

                'valid'=>$valid

            ]

        );



        return $valid;


    }






    /**
     * Save photo data
     */
    public function save(
        array $message
    ): array {


        Logger::write(

            'photos_handler_debug',

            [

                'step'=>'save_start',

                'platform'=>$message['platform'] ?? null,

                'type'=>$message['type'] ?? null

            ]

        );





        /*
        |--------------------------------------------------------------------------
        | Check DONE
        |--------------------------------------------------------------------------
        */


        $text = strtolower(

            trim(

                $message['text'] ?? ''

            )

        );



        if ($text === 'done') {


            Logger::write(

                'photos_handler_debug',

                [

                    'step'=>'photos_finished'

                ]

            );



            return [

                'photos_complete'=>true

            ];


        }






        /*
        |--------------------------------------------------------------------------
        | Create Downloader
        |--------------------------------------------------------------------------
        */


        try {


            $downloader = new MediaDownloader();



            Logger::write(

                'photos_handler_debug',

                [

                    'step'=>'downloader_created'

                ]

            );



        } catch(\Throwable $e) {


            Logger::write(

                'photos_handler_error',

                [

                    'step'=>'downloader_exception',

                    'message'=>$e->getMessage(),

                    'file'=>$e->getFile(),

                    'line'=>$e->getLine()

                ]

            );



            return [

                'photos'=>[]

            ];


        }






        /*
        |--------------------------------------------------------------------------
        | Download Media
        |--------------------------------------------------------------------------
        */


        try {


            Logger::write(

                'photos_handler_debug',

                [

                    'step'=>'before_download',

                    'message_platform'=>$message['platform'] ?? null

                ]

            );



            $media = $downloader->download(

                $message['platform'],

                $message,

                'marketplace'

            );



            Logger::write(

                'photos_handler_debug',

                [

                    'step'=>'after_download',

                    'media'=>$media

                ]

            );



        } catch(\Throwable $e) {


            Logger::write(

                'photos_handler_error',

                [

                    'step'=>'download_exception',

                    'message'=>$e->getMessage(),

                    'file'=>$e->getFile(),

                    'line'=>$e->getLine()

                ]

            );



            return [

                'photos'=>[]

            ];


        }






        /*
        |--------------------------------------------------------------------------
        | Check Download Result
        |--------------------------------------------------------------------------
        */


        if ($media === null) {


            Logger::write(

                'photos_handler_error',

                [

                    'step'=>'media_null'

                ]

            );



            return [

                'photos'=>[]

            ];


        }







        /*
        |--------------------------------------------------------------------------
        | Prepare Return Data
        |--------------------------------------------------------------------------
        */


        $result = [

            'photos'=>[

                $media

            ]

        ];





        Logger::write(

            'photos_handler_debug',

            [

                'step'=>'return_result',

                'result'=>$result

            ]

        );





        return $result;


    }



}