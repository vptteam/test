<?php

declare(strict_types=1);

namespace Modules\Marketplace\Handlers;

use Core\ReplyInterface;

class LocationHandler
{

    public function ask(
        ReplyInterface $reply,
        string $phone,
        array $data = []
    ): void {

        $reply->text(

            $phone,

            "📍 Enter the item's location.\n\n".
            "Example:\nKubwa, Abuja"

        );

    }


    public function validate(
        array $message
    ): bool {

        return trim(

            $message['text'] ?? ''

        ) !== '';

    }


    public function save(
        array $message
    ): array {

        return [

            'location' => trim(

                $message['text'] ?? ''

            )

        ];

    }

}