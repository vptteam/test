<?php

declare(strict_types=1);

namespace Listeners\Health;

class HealthListener
{
    public function handle(): void
    {
        http_response_code(200);

        echo 'SENDAM BOT ONLINE';
    }
}