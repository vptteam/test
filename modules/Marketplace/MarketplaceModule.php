<?php

declare(strict_types=1);

namespace Modules\Marketplace;

use Modules\ModuleInterface;
use Core\ReplyInterface;
use Models\Conversation;


class MarketplaceModule implements ModuleInterface
{


    public function name(): string
    {
        return 'Marketplace';
    }





    public function canHandle(

        array $user,

        array $message

    ): bool {


        $text = strtolower(

            trim(

                $message['text'] ?? ''

            )

        );


        return in_array(

            $text,

            [

                'marketplace',
                'market',
                'sell',
                'post',
                'listing',
                'create_listing'

            ],

            true

        );

    }





    public function handle(

        array $user,

        array $message,

        ReplyInterface $reply

    ): void {


        $conversation = new Conversation();



        /*
        |--------------------------------------------------------------------------
        | Start Marketplace Listing Workflow
        |--------------------------------------------------------------------------
        */


        $conversation->start(

            (int)$user['id'],

            'Marketplace',

            'create_listing',

            'photos'

        );




        $reply->text(

            $message['phone'],

            "🛒 SENDAM Marketplace\n\n"

            .

            "Let's create your listing.\n\n"

            .

            "📸 First, send up to 5 photos.\n\n"

            .

            "When finished type DONE."

        );


    }


}