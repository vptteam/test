<?php

declare(strict_types=1);

namespace Modules\Marketplace\Handlers;

use Core\ReplyInterface;
use Core\Logger;

class ConfirmHandler
{

    public function ask(
        ReplyInterface $reply,
        string $phone,
        array $listing = []
    ): void {


Logger::write(
    'confirm_handler_listing_debug',
    $listing
);
        $photos = count(
            $listing['photos'] ?? []
        );


        $text =

            "📋 LISTING PREVIEW\n\n".

            "🏷 Title:\n".
            ($listing['title'] ?? 'Not provided').
            "\n\n".

            "💰 Price:\n₦".
            ($listing['price'] ?? 'Not provided').
            "\n\n".

            "📍 Exact Location:\n".
            ($listing['location'] ?? 'Not provided').
            "\n\n".

            "📝 Description + Phone Number + Other Details:\n".
            ($listing['description'] ?? 'Not provided').
            "\n\n".

            "📸 Photos:\n".
            $photos.
            " uploaded\n\n".

            "━━━━━━━━━━━━━━\n\n".

            "✅ Publish this listing?\n\n".

            "Reply YES 👍\n".

            "Reply NO ❌";


        $reply->text(

            $phone,

            $text

        );

    }



    public function validate(
        array $message
    ): bool {

        return true;

    }



    public function save(
        array $message
    ): array {

        return [];

    }

}