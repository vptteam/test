<?php

declare(strict_types=1);

namespace Modules\Marketplace;

use Core\Conversation;
use Core\ReplyInterface;

class MarketplaceConversation
{
    public function process(

        array $user,

        array $conversation,

        array $message,

        ReplyInterface $reply

    ): void
    {

        switch ($conversation['step']) {

            case 'photo':

                $reply->text(

                    $message['phone'],

                    "Now enter the item title."

                );

                break;

            case 'title':

                $reply->text(

                    $message['phone'],

                    "Enter the selling price."

                );

                break;

            case 'price':

                $reply->text(

                    $message['phone'],

                    "Enter your location."

                );

                break;

            case 'location':

                $reply->text(

                    $message['phone'],

                    "Describe the item."

                );

                break;

            case 'description':

                $reply->text(

                    $message['phone'],

                    "✅ Publish listing?\n\nYes / No"

                );

                break;

        }

    }

}