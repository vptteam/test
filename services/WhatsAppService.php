<?php

declare(strict_types=1);

namespace Services;

class WhatsAppService
{

    public function sendText(

        string $phone,

        string $message

    ): void
    {

        $url =

            "https://graph.facebook.com/v23.0/"

            .

            WHATSAPP_PHONE_ID

            .

            "/messages";

        $payload = [

            "messaging_product" => "whatsapp",

            "to" => $phone,

            "type" => "text",

            "text" => [

                "body" => $message

            ]

        ];

        $headers = [

            "Authorization: Bearer " . WHATSAPP_TOKEN,

            "Content-Type: application/json"

        ];

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_POST, true);

        curl_setopt(

            $ch,

            CURLOPT_HTTPHEADER,

            $headers

        );

        curl_setopt(

            $ch,

            CURLOPT_POSTFIELDS,

            json_encode($payload)

        );

        curl_setopt(

            $ch,

            CURLOPT_RETURNTRANSFER,

            true

        );

        curl_exec($ch);

        curl_close($ch);

    }

}