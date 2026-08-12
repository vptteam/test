<?php

declare(strict_types=1);

namespace Modules\Gym;

use Modules\ModuleInterface;
use Core\ReplyInterface;

class GymModule implements ModuleInterface
{

    public function name(): string
    {

        return 'Gym';

    }

    public function canHandle(

        array $user,

        array $message

    ): bool
    {

        return trim($message['text']) == '3';

    }

    public function handle(

        array $user,

        array $message,

        ReplyInterface $reply

    ): void
    {

        $reply->text(

            $message['phone'],

            "🏋 SENDAM Gym"

        );

    }

}