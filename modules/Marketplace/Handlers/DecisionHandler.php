<?php

declare(strict_types=1);

namespace Modules\Marketplace\Handlers;

use Core\ReplyInterface;

class DecisionHandler
{

    /**
     * Ask user for final decision.
     */
    public function ask(
        ReplyInterface $reply,
        string $phone,
        array $data = []
    ): void {


        $reply->text(

            $phone,

            "Reply with:\n\n".
            "YES - Publish Listing ✅\n".
            "NO - Cancel Listing ❌"

        );

    }



    /**
     * Validate response.
     */
    public function validate(
        array $message
    ): bool {


        $answer = strtoupper(

            trim(

                $message['text'] ?? ''

            )

        );


        return in_array(

            $answer,

            [

                'YES',
                'NO'

            ],

            true

        );

    }



    /**
     * Save decision.
     */
    public function save(
        array $message
    ): array {


        return [

            'decision' => strtoupper(

                trim(

                    $message['text'] ?? ''

                )

            )

        ];

    }

}