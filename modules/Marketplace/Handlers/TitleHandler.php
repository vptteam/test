<?php

declare(strict_types=1);

namespace Modules\Marketplace\Handlers;

use Core\ReplyInterface;

class TitleHandler
{

    public function ask(
        ReplyInterface $reply,
        string $phone,
        array $data = []
    ): void
    {

        $reply->text(

            $phone,

            "📝 What is the item title?"

        );

    }


    public function validate(
        array $message
    ): bool
    {

        return !empty(

            trim(

                $message['text'] ?? ''

            )

        );

    }


    public function save(
        array $message
    ): array
    {

        return [

            'title' => trim(

                $message['text'] ?? ''

            )

        ];

    }

}