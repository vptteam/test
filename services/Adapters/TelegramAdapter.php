<?php

declare(strict_types=1);

namespace Services\Adapters;

class TelegramAdapter
{
    /**
     * Get Telegram File Information
     */
    public function file(string $fileId): ?array
    {
        $url =

            "https://api.telegram.org/bot"

            .

            TELEGRAM_BOT_TOKEN

            .

            "/getFile?file_id="

            .

            urlencode($fileId);

        $response = file_get_contents($url);

        if (!$response) {

            return null;

        }

        $json = json_decode($response, true);

        return $json['result'] ?? null;

    }

    /**
     * Download Telegram File
     */
    public function download(
        string $path
    ): ?string {

        $url =

            "https://api.telegram.org/file/bot"

            .

            TELEGRAM_BOT_TOKEN

            .

            "/"

            .

            $path;

        $binary = file_get_contents($url);

        return $binary ?: null;

    }
}