<?php

declare(strict_types=1);

namespace Modules\Marketplace\Handlers;

use Core\ReplyInterface;

class PriceHandler
{

    public function ask(
        ReplyInterface $reply,
        string $phone,
        array $data = []
    ): void
    {

        $reply->text(

    $phone,

    "💰 How much are you selling it for?\n\n"

    .

    "Enter the price in Naira.\n\n"

    .

    "Please enter numbers only.\n"

    .

    "Do not add ₦ sign or commas.\n\n"

    .

    "Examples:\n"

    .

    "5000\n"

    .

    "250000"

);

    }


    public function validate(
        array $message
    ): bool
    {

        $price = trim(

            $message['text'] ?? ''

        );


        return $price !== '';

    }


    public function save(
        array $message
    ): array
    {

        return [

            'price' => trim(

                $message['text'] ?? ''

            )

        ];

    }

}