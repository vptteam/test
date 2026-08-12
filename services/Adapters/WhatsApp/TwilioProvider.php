<?php

declare(strict_types=1);

namespace Services\Adapters\WhatsApp;

use Core\Logger;
use Throwable;


class TwilioProvider implements WhatsAppProviderInterface
{


    protected string $endpoint;



    public function __construct()
    {

        $this->endpoint =
            "https://api.twilio.com/2010-04-01/Accounts/"
            . TWILIO_ACCOUNT_SID
            . "/Messages.json";


        Logger::write(

            'twilio_provider_debug',

            [

                'step'=>'CONSTRUCTOR',

                'endpoint'=>$this->endpoint

            ]

        );

    }







    /**
     * Send text message
     */
    public function text(

        string $recipient,

        string $message

    ): bool {


        Logger::write(

            'twilio_provider_debug',

            [

                'step'=>'TEXT_START',

                'recipient'=>$recipient,

                'message'=>$message

            ]

        );



        return $this->request(

            [

                'To'=>$this->formatWhatsAppNumber(
                    $recipient
                ),

                'From'=>TWILIO_WHATSAPP_NUMBER,

                'Body'=>$message

            ]

        );


    }









    /**
     * Send image
     */
    public function image(

        string $recipient,

        string $image,

        string $caption=''

    ): bool {



        Logger::write(

            'twilio_provider_debug',

            [

                'step'=>'IMAGE_START',

                'recipient'=>$recipient,

                'image'=>$image

            ]

        );



        return $this->request(

            [

                'To'=>$this->formatWhatsAppNumber(
                    $recipient
                ),

                'From'=>TWILIO_WHATSAPP_NUMBER,

                'Body'=>$caption,

                'MediaUrl'=>$image

            ]

        );


    }









    /**
     * Send document
     */
    public function document(

        string $recipient,

        string $document,

        string $caption=''

    ): bool {



        Logger::write(

            'twilio_provider_debug',

            [

                'step'=>'DOCUMENT_START',

                'recipient'=>$recipient

            ]

        );



        return $this->request(

            [

                'To'=>$this->formatWhatsAppNumber(
                    $recipient
                ),

                'From'=>TWILIO_WHATSAPP_NUMBER,

                'Body'=>$caption,

                'MediaUrl'=>$document

            ]

        );


    }









    /**
     * WhatsApp menu fallback
     */
    public function menu(

        string $recipient,

        string $title,

        array $items

    ): bool {


        $text = $title . "\n\n";


        foreach($items as $index=>$item){


            $text .=

                ($index + 1)
                .
                ". "
                .
                $item
                .
                "\n";


        }



        return $this->text(

            $recipient,

            $text

        );


    }









    /**
     * Buttons fallback
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









    public function media(

        string $mediaId

    ): ?array {

        return null;

    }









    public function download(

        string $url

    ): ?string {


        return @file_get_contents($url) ?: null;


    }









    /**
     * Format WhatsApp number
     */
    protected function formatWhatsAppNumber(

        string $number

    ): string {


        $original = $number;



        $number = trim($number);



        if(

            str_starts_with(

                $number,

                'whatsapp:'

            )

        ){

            return $number;

        }



        if(

            !str_starts_with(

                $number,

                '+'

            )

        ){

            $number = '+' . $number;

        }



        $formatted =
            'whatsapp:' . $number;



        Logger::write(

            'twilio_provider_debug',

            [

                'step'=>'FORMAT_NUMBER',

                'original'=>$original,

                'formatted'=>$formatted

            ]

        );



        return $formatted;


    }









    /**
     * Send request to Twilio
     */
    protected function request(array $payload): bool
{

    \Core\Logger::write(
        'twilio_whatsapp_request',
        [
            'step'=>'REQUEST_START',
            'payload'=>$payload
        ]
    );


    $ch = curl_init($this->endpoint);



    curl_setopt_array($ch,[


        CURLOPT_RETURNTRANSFER => true,


        CURLOPT_POST => true,


        CURLOPT_POSTFIELDS => http_build_query($payload),


        CURLOPT_USERPWD =>
            TWILIO_ACCOUNT_SID
            .
            ':'
            .
            TWILIO_AUTH_TOKEN,


        CURLOPT_HTTPHEADER => [

            'Content-Type: application/x-www-form-urlencoded'

        ],


        CURLOPT_TIMEOUT => 30


    ]);





    $response = curl_exec($ch);



    $http = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );



    $curlError = curl_error($ch);



    curl_close($ch);




    $decoded = json_decode(
    $response ?: '',
    true
);





    /*
    |--------------------------------------------------------------------------
    | General Twilio Response Log
    |--------------------------------------------------------------------------
    */


    \Core\Logger::write(

        'twilio_whatsapp_response',

        [

            'http'=>$http,

            'payload'=>$payload,

            'response'=>$response,

            'curl_error'=>$curlError

        ]

    );







    /*
    |--------------------------------------------------------------------------
    | CURL Failure
    |--------------------------------------------------------------------------
    */


    if($curlError){


        \Core\Logger::write(

            'twilio_whatsapp_error',

            [

                'step'=>'CURL_FAILED',

                'error'=>$curlError

            ]

        );


        return false;


    }







    /*
    |--------------------------------------------------------------------------
    | Twilio Daily Limit Detection
    |--------------------------------------------------------------------------
    */


    if(

        isset($decoded['code'])

        &&

        (int)$decoded['code'] === 63038

    ){


        \Core\Logger::write(

            'twilio_limit_error',

            [

                'step'=>'DAILY_MESSAGE_LIMIT_REACHED',

                'account'=>TWILIO_ACCOUNT_SID,

                'message'=>$decoded['message'] ?? null,

                'status'=>$decoded['status'] ?? null,

                'more_info'=>$decoded['more_info'] ?? null

            ]

        );



        return false;


    }








    /*
    |--------------------------------------------------------------------------
    | Other Twilio API Errors
    |--------------------------------------------------------------------------
    */


    if(

        isset($decoded['code'])

        &&

        $http >= 400

    ){


        \Core\Logger::write(

            'twilio_whatsapp_error',

            [

                'step'=>'TWILIO_API_ERROR',

                'code'=>$decoded['code'],

                'message'=>$decoded['message'] ?? null,

                'status'=>$decoded['status'] ?? null

            ]

        );


        return false;


    }








    /*
    |--------------------------------------------------------------------------
    | Success
    |--------------------------------------------------------------------------
    */


    if(

        $http >= 200

        &&

        $http < 300

    ){


        \Core\Logger::write(

            'twilio_whatsapp_success',

            [
    'step'=>'MESSAGE_ACCEPTED',

    'http'=>$http,

    'sid'=>$decoded['sid'] ?? null,

    'status'=>$decoded['status'] ?? null

]

        );


        return true;


    }






    return false;


}

}