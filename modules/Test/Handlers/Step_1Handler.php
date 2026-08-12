<?php

declare(strict_types=1);

namespace Modules\Test\Handlers;

use Core\ReplyInterface;


class Step_1Handler
{


    /**
     * Validate user input
     */
    public function validate(
        array $message
    ): bool {


        $text = trim(
            $message['text'] ?? ''
        );


        if ($text === '') {

            return false;

        }


        return true;

    }




    /**
     * Ask user for this step
     */
    public function ask(
        ReplyInterface $reply,
        string $phone,
        array $data = []
    ): void {


        $reply->text(

            $phone,

            "Please enter your information."

        );

    }





    /**
     * Save received data
     */
    public function save(
        array $message
    ): array {


        return [

            'step_1_answer' =>

                trim(
                    $message['text'] ?? ''
                )

        ];

    }




    /**
     * Optional final action
     */
    public function execute(
        array $user,
        array $data,
        ReplyInterface $reply
    ): void {


        $reply->text(

            $user['phone'],

            "✅ Step 1 completed."

        );


    }


}