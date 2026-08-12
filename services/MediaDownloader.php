<?php

declare(strict_types=1);

namespace Services;

use Services\Adapters\WhatsApp\WhatsAppMedia;
use Services\Adapters\Telegram\TelegramMedia;
use Core\Logger;

class MediaDownloader
{

    /**
     * Download media from the appropriate platform.
     */
    public function download(
        string $platform,
        array $message,
        string $folder = 'marketplace'
    ): ?array {


        Logger::write(
            'media_downloader_debug',
            [
                'step'=>'download_start',
                'platform'=>$platform,
                'folder'=>$folder
            ]
        );


        switch (strtolower($platform)) {


            case 'telegram':

                return $this->telegram(
                    $message,
                    $folder
                );


            case 'whatsapp':

                return $this->whatsapp(
                    $message,
                    $folder
                );


            default:


                Logger::write(
                    'media_downloader_debug',
                    [
                        'step'=>'unsupported_platform',
                        'platform'=>$platform
                    ]
                );


                return null;

        }

    }



    /**
     * Telegram Media
     */
    protected function telegram(
        array $message,
        string $folder
    ): ?array {


        Logger::write(
            'media_downloader_debug',
            [
                'step'=>'telegram_start',
                'raw_keys'=>array_keys(
                    $message['raw'] ?? []
                )
            ]
        );



        if (
            empty($message['raw']['message']['photo'])
        ) {


            Logger::write(
                'media_downloader_debug',
                [
                    'step'=>'no_photo_found'
                ]
            );


            return null;

        }



        /*
        |--------------------------------------------------------------------------
        | Telegram sends multiple photo sizes
        |--------------------------------------------------------------------------
        |
        | The last photo object is usually the highest resolution.
        |
        */

        $photos = $message['raw']['message']['photo'];


        $photo = end($photos);



        Logger::write(
            'media_downloader_debug',
            [
                'step'=>'photo_selected',
                'photo'=>$photo
            ]
        );



        try {


            Logger::write(
                'media_downloader_debug',
                [
                    'step'=>'before_new_telegram_media'
                ]
            );



            $telegram = new TelegramMedia();



            Logger::write(
                'media_downloader_debug',
                [
                    'step'=>'telegram_media_created'
                ]
            );



            $result = $telegram->photo(

                $photo['file_id'],

                $folder

            );



            Logger::write(
                'media_downloader_debug',
                [
                    'step'=>'telegram_photo_complete',
                    'result'=>$result
                ]
            );



            return $result;



        } catch(\Throwable $e) {



            Logger::write(
                'media_downloader_error',
                [
                    'message'=>$e->getMessage(),
                    'file'=>$e->getFile(),
                    'line'=>$e->getLine()
                ]
            );



            return null;

        }

    }




    /**
     * WhatsApp Media
     */
    protected function whatsapp(
        array $message,
        string $folder
    ): ?array {


        try {


            $media = new WhatsAppMedia();



            return $media->image(

                $message,

                $folder

            );



        } catch(\Throwable $e) {



            Logger::write(
                'whatsapp_media_error',
                [
                    'message'=>$e->getMessage(),
                    'file'=>$e->getFile(),
                    'line'=>$e->getLine()
                ]
            );



            return null;

        }

    }


}