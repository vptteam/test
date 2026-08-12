<?php

declare(strict_types=1);


namespace Modules\Escrow\Services;

class BotMenuService
{

    /**
     * Main Menu
     */
    public function main(
        $reply,
        array $message,
        ?string $intro = null
    ): void {

        $text = '';

        if ($intro !== null) {

            $text .= $intro . "\n\n";

        }

       $text .=
    "👋 Ready to sell today!\n\n"

    . "🛍 SELL - Post your item on SENDAM\n"

    . "🛡 ESCROW - Receive payment from strangers\n\n"
    

    . "💬 Need help?\n"

    . "Chat with us on WhatsApp:\n"

    . "https://wa.me/2348123370000";

    
 
$text .=

"\n\n━━━━━━━━━━━━━━\n\n"

 . "Ready to sell?*\n\n"
 . "Reply with either:\n\n"

 . "SELL or ESCROW  \n";


$reply->text(
    $message['phone'],
    $text
);

    }


    /**
     * Simple Text Menu
     */
    public function text(
        $reply,
        string $phone,
        string $text
    ): void {

        $reply->text(
            $phone,
            $text
        );

    }


    /**
     * Success Message
     */
    public function success(
        $reply,
        string $phone,
        string $text
    ): void {

        $reply->text(
            $phone,
            "✅ {$text}"
        );

    }


    /**
     * Error Message
     */
    public function error(
        $reply,
        string $phone,
        string $text
    ): void {

        $reply->text(
            $phone,
            "⚠️ {$text}"
        );

    }


    /**
     * Information Message
     */
    public function info(
        $reply,
        string $phone,
        string $text
    ): void {

        $reply->text(
            $phone,
            "ℹ️ {$text}"
        );

    }

}