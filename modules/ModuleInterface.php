<?php

declare(strict_types=1);

namespace Modules;

use Core\ReplyInterface;

interface ModuleInterface
{

    /*
    |--------------------------------------------------------------------------
    | Module Name
    |--------------------------------------------------------------------------
    */

    public function name(): string;

    /*
    |--------------------------------------------------------------------------
    | Can Handle?
    |--------------------------------------------------------------------------
    */

    public function canHandle(

        array $user,

        array $message

    ): bool;

    /*
    |--------------------------------------------------------------------------
    | Process
    |--------------------------------------------------------------------------
    */

    public function handle(

        array $user,

        array $message,

        ReplyInterface $reply

    ): void;

}