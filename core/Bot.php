<?php

declare(strict_types=1);

namespace Core;

class Bot
{

    public function run(): void
    {

        $dispatcher = new Dispatcher();

        $dispatcher->dispatch();

    }

}