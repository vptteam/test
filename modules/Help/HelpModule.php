<?php

declare(strict_types=1);

namespace Modules\Help;

use Modules\ModuleInterface;
use Core\ReplyInterface;

class HelpModule implements ModuleInterface
{

    public function name(): string
    {

        return 'Help';

    }

    public function canHandle(

        array $user,

        array $message

    ): bool
    {

        return trim($message['text']) == '4';

    }

    public function handle(

        array $user,

        array $message,

        ReplyInterface $reply

    ): void
    {

        $reply->text(

            $message['phone'],

            "📞 SENDAM Help"

        );

    }

}