<?php

declare(strict_types=1);

namespace Modules\Payments;

use Modules\ModuleInterface;
use Core\ReplyInterface;

class PaymentModule implements ModuleInterface
{

    public function name(): string
    {

        return 'Payments';

    }

    public function canHandle(

        array $user,

        array $message

    ): bool
    {

        return trim($message['text']) == '2';

    }

    public function handle(

        array $user,

        array $message,

        ReplyInterface $reply

    ): void
    {

        $reply->text(

            $message['phone'],

            "💳 Payments Module"

        );

    }

}