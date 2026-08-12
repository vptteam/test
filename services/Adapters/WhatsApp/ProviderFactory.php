<?php

declare(strict_types=1);

namespace Services\Adapters\WhatsApp;

class ProviderFactory
{
    public static function make(): WhatsAppProviderInterface
    {
        return match (
            strtolower(WHATSAPP_PROVIDER)
        ) {

            'meta' => new MetaProvider(),

            default => new TwilioProvider()

        };
    }
}