<?php

declare(strict_types=1);

namespace Services\Adapters\Telegram;

use Core\Logger;


class TelegramPublisher
{

    protected TelegramApi $api;


    protected string $channel;



    public function __construct()
    {

        $this->api = new TelegramApi();


        /*
        |--------------------------------------------------------------------------
        | Telegram Channel
        |--------------------------------------------------------------------------
        */

        $this->channel = '@nigerianmarkethub';



        Logger::write(

            'telegram_publisher',

            [

                'step'=>'constructor',

                'channel'=>$this->channel

            ]

        );

    }





    /**
     * Publish Listing
     */
    public function publish(

        array $listing

    ): ?array {


        Logger::write(

            'telegram_publisher',

            [

                'step'=>'publish_start',

                'listing'=>$listing

            ]

        );



        if(
            empty($listing['photos'])
        ){

            Logger::write(

                'telegram_publisher_error',

                [

                    'step'=>'no_photos'

                ]

            );


            return null;

        }





        /*
        |--------------------------------------------------------------------------
        | Build Caption
        |--------------------------------------------------------------------------
        */


        $caption = $this->caption(

            $listing

        );



        Logger::write(

            'telegram_publisher',

            [

                'step'=>'caption_created',

                'caption'=>$caption

            ]

        );





        /*
        |--------------------------------------------------------------------------
        | Multiple Photos
        |--------------------------------------------------------------------------
        */


        $media = [];



        foreach(

            $listing['photos']

            as

            $photo

        ){


            foreach(
    $listing['photos'] as $index=>$photo
){

    $item = [

        'type'=>'photo',

        'media'=> 'attach://photo'.$index

    ];


    if($index === 0){

        $item['caption']=$caption;

        $item['parse_mode']='HTML';

    }


    $media[]=$item;


    $files['photo'.$index] = new \CURLFile(
        $photo['filepath']
    );

}


        }





        /*
        |--------------------------------------------------------------------------
        | Telegram album
        |--------------------------------------------------------------------------
        */


        $payload = [

    'chat_id'=>$this->channel,

    'media'=>json_encode($media)

];


$payload = array_merge(
    $payload,
    $files
);




        Logger::write(

            'telegram_publisher',

            [

                'step'=>'before_send_media',

                'count'=>count($media)

            ]

        );




        $result = $this->api->request(

            'sendMediaGroup',

            $payload

        );




        Logger::write(

            'telegram_publisher',

            [

                'step'=>'telegram_response',

                'response'=>$result

            ]

        );



        if(
            empty($result['ok'])
        ){


            Logger::write(

                'telegram_publisher_error',

                [

                    'step'=>'publish_failed',

                    'response'=>$result

                ]

            );


            return null;

        }




        Logger::write(

            'telegram_publisher',

            [

                'step'=>'publish_success'

            ]

        );



        return $result;


    }






    /**
     * Listing Caption
     */
    protected function caption(

        array $listing

    ): string {


        return

        "🛒 NEW MARKETPLACE LISTING\n\n".

        ($listing['title'] ?? 'Product').

        "\n\n".

        ($listing['description'] ?? '').

        "\n\n".

        "📍 Nigeria Marketplace\n".

        "📲 Contact seller directly";

    }


}