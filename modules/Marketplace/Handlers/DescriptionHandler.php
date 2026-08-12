<?php

declare(strict_types=1);

namespace Modules\Marketplace\Handlers;

use Core\ReplyInterface;

class DescriptionHandler
{

    public function ask(
        ReplyInterface $reply,
        string $phone,
        array $data = []
    ): void {

        $reply->text(

    $phone,

    "📝 Describe the item you are selling.\n\n"

    .

    "Please include:\n"

    .

    "✅ Full details about the item\n"

    .

    "✅ Condition (new, used, faulty, etc.)\n"

    .

    "✅ Your phone number for buyers to contact you\n"

    .

    "✅ WhatsApp number (if available)\n\n"

    .

    "Example:\n"

    .

    "iPhone 11, 64GB, fairly used, battery health 85%.\n"

    .

    "Contact: 080XXXXXXXX\n"

    .

    "WhatsApp: 080XXXXXXXX"

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

            'description' => trim(

                $message['text'] ?? ''

            )

        ];

    }

}