<?php

declare(strict_types=1);

namespace Services\Adapters\WhatsApp;

use Core\Logger;


class MetaProvider implements WhatsAppProviderInterface
{


    protected string $endpoint;

    protected string $graph;




    public function __construct()
    {


        $version = defined('META_GRAPH_VERSION')
            ? META_GRAPH_VERSION
            : 'v23.0';



        $this->graph =
            "https://graph.facebook.com/"
            .
            $version;



        $this->endpoint =
            $this->graph
            .
            "/"
            .
            WHATSAPP_PHONE_NUMBER_ID
            .
            "/messages";




        Logger::write(

            'meta_provider_loaded',

            [

                'graph'=>$this->graph,

                'phone_id'=>WHATSAPP_PHONE_NUMBER_ID

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


        return $this->request([


            'messaging_product'=>'whatsapp',


            'to'=>$this->phone($recipient),


            'type'=>'text',


            'text'=>[

                'body'=>$message

            ]


        ]);


    }









    /**
     * Send Image
     */
    public function image(
        string $recipient,
        string $image,
        string $caption=''
    ): bool {


        if(
            !str_starts_with(
                $image,
                'http'
            )
        ){

            Logger::write(

                'meta_media_error',

                [

                    'error'=>'Invalid image URL',

                    'url'=>$image

                ]

            );


            return false;

        }



        return $this->request([


            'messaging_product'=>'whatsapp',


            'to'=>$this->phone($recipient),


            'type'=>'image',


            'image'=>[

                'link'=>$image,

                'caption'=>$caption

            ]


        ]);


    }









    /**
     * Send Document
     */
    public function document(
        string $recipient,
        string $document,
        string $caption=''
    ): bool {


        if(
            !str_starts_with(
                $document,
                'http'
            )
        ){

            return false;

        }



        return $this->request([


            'messaging_product'=>'whatsapp',


            'to'=>$this->phone($recipient),


            'type'=>'document',


            'document'=>[

                'link'=>$document,

                'caption'=>$caption

            ]


        ]);


    }









    /**
     * Text Menu fallback
     */
    public function menu(
        string $recipient,
        string $title,
        array $items
    ): bool {


        $message =
            $title
            .
            "\n\n";



        foreach(
            $items as $key=>$item
        ){

            $message .=

                ($key + 1)
                .
                ". "
                .
                $item
                .
                "\n";

        }



        return $this->text(

            $recipient,

            $message

        );


    }









    /**
     * Interactive buttons
     *
     * Temporary text fallback.
     */
    public function buttons(
        string $recipient,
        string $message,
        array $buttons
    ): bool {


        return $this->text(

            $recipient,

            $message

        );


    }









    /**
     * Get Meta media information
     */
    public function media(
        string $mediaId
    ): ?array {


        $url =
            $this->graph
            .
            "/"
            .
            $mediaId;



        $response = $this->curl(

            $url

        );



        if(!$response){

            return null;

        }



        return json_decode(

            $response,

            true

        );


    }









    /**
     * Download WhatsApp media
     */
    public function download(
        string $url
    ): ?string {


        $ch = curl_init($url);



        curl_setopt_array(

            $ch,

            [


                CURLOPT_RETURNTRANSFER=>true,


                CURLOPT_FOLLOWLOCATION=>true,


                CURLOPT_HTTPHEADER=>[


                    "Authorization: Bearer "
                    .
                    WHATSAPP_TOKEN


                ],


                CURLOPT_TIMEOUT=>60


            ]

        );



        $binary = curl_exec($ch);



        $error = curl_error($ch);



        curl_close($ch);




        if($error){


            Logger::write(

                'meta_media_download_error',

                [

                    'error'=>$error

                ]

            );


            return null;


        }



        return $binary ?: null;


    }









    /**
     * Send Meta API Request
     */
    protected function request(
        array $payload
    ): bool {


        $ch = curl_init(

            $this->endpoint

        );




        curl_setopt_array(

            $ch,

            [


                CURLOPT_RETURNTRANSFER=>true,


                CURLOPT_POST=>true,


                CURLOPT_HTTPHEADER=>[


                    "Authorization: Bearer "
                    .
                    WHATSAPP_TOKEN,


                    "Content-Type: application/json"


                ],



                CURLOPT_POSTFIELDS=>

                    json_encode(
                        $payload
                    ),



                CURLOPT_TIMEOUT=>60


            ]

        );




        $response = curl_exec($ch);



        $http = curl_getinfo(

            $ch,

            CURLINFO_HTTP_CODE

        );



        $error = curl_error($ch);



        curl_close($ch);






        $decoded = json_decode(

            $response,

            true

        );






        Logger::write(

            'meta_whatsapp_response',

            [

                'http'=>$http,

                'response'=>$decoded,

                'raw'=>$response,

                'curl_error'=>$error,

                'payload'=>$payload

            ]

        );






        if($error){

            return false;

        }






        if(

            $http >= 200

            &&

            $http < 300

            &&

            isset(
                $decoded['messages']
            )

        ){

            return true;

        }






        return false;


    }









    /**
     * Generic GET request
     */
    protected function curl(
        string $url
    ): ?string {


        $ch = curl_init($url);



        curl_setopt_array(

            $ch,

            [


                CURLOPT_RETURNTRANSFER=>true,


                CURLOPT_FOLLOWLOCATION=>true,


                CURLOPT_HTTPHEADER=>[


                    "Authorization: Bearer "
                    .
                    WHATSAPP_TOKEN


                ],


                CURLOPT_TIMEOUT=>60


            ]

        );



        $response = curl_exec($ch);



        $error = curl_error($ch);



        curl_close($ch);




        if($error){


            Logger::write(

                'meta_curl_error',

                [

                    'error'=>$error

                ]

            );


            return null;


        }



        return $response ?: null;


    }









    /**
     * Normalize Phone Number
     */
    protected function phone(
        string $number
    ): string {


        $number = preg_replace(

            '/[^0-9]/',

            '',

            $number

        );




        if(
            str_starts_with(
                $number,
                '0'
            )
        ){

            $number =

                '234'
                .
                substr(
                    $number,
                    1
                );

        }



        return $number;


    }



}