<?php

declare(strict_types=1);

namespace Services\Adapters;

class WhatsAppAdapter
{
    /**
     * Get Meta Media Information
     */
    public function media(string $mediaId): ?array
    {
        $url = "https://graph.facebook.com/v23.0/{$mediaId}";

        $ch = curl_init($url);

        curl_setopt_array($ch, [

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_HTTPHEADER => [

                "Authorization: Bearer " . WHATSAPP_TOKEN

            ]

        ]);

        $response = curl_exec($ch);

        curl_close($ch);

        if (!$response) {

            return null;

        }

        return json_decode($response, true);

    }

    /**
     * Download Meta File
     */
    public function download(string $url): ?string
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_HTTPHEADER => [

                "Authorization: Bearer " . WHATSAPP_TOKEN

            ]

        ]);

        $binary = curl_exec($ch);

        curl_close($ch);

        return $binary ?: null;
    }
}