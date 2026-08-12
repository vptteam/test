<?php

declare(strict_types=1);

namespace Services\Marketplace;

use Core\Logger;
use Services\Adapters\WhatsApp\ProviderFactory;


class MarketplacePublisher
{


    public function publish(
        array $listing
    ): bool {


        Logger::write(
            'marketplace_publish_start',
            $listing
        );


        /*
        |--------------------------------------------------------------------------
        | WhatsApp Notification
        |--------------------------------------------------------------------------
        */


        $this->sendWhatsAppNotification(
            $listing
        );



        /*
        |--------------------------------------------------------------------------
        | Telegram Channel
        |--------------------------------------------------------------------------
        */


        // Existing Telegram publisher will connect here



        Logger::write(
            'marketplace_publish_complete',
            [
                'status'=>true
            ]
        );


        return true;

    }





    protected function sendWhatsAppNotification(
        array $listing
    ): void {


        $whatsapp = ProviderFactory::make();



        $message =

            "🚀 NEW SENDAM LISTING\n\n".

            "🏷 {$listing['title']}\n\n".

            "💰 ₦{$listing['price']}\n\n".

            "📍 {$listing['location']}\n\n".

            "📝 {$listing['description']}\n\n".

            "Seller:\n".

            "08030981624";



        $whatsapp->text(

            SENDAM_ADMIN_WHATSAPP,

            $message

        );



        Logger::write(
            'whatsapp_listing_sent',
            [
                'recipient'=>SENDAM_ADMIN_WHATSAPP
            ]
        );


    }


}