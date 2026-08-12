<?php

declare(strict_types=1);

namespace Services\USSD;

use Core\Logger;
use Services\USSD\Providers\AfricaTalkingUssdProvider;
use Services\USSD\Providers\TermiiUssdProvider;
use Services\USSD\Providers\TwilioUssdProvider;
use Services\USSD\Providers\ArkeselUssdProvider;
use Throwable;

class USSDProviderFactory
{
    /**
     * ---------------------------------------------------------
     * CREATE PROVIDER
     * ---------------------------------------------------------
     *
     * Returns the configured USSD provider adapter.
     */
    public static function make(
        ?string $provider = null
    ): object {

        try {

            $provider =
                strtolower(
                    trim(
                        (string)(
                            $provider
                            ??
                            (
                                defined('USSD_PROVIDER')
                                    ? USSD_PROVIDER
                                    : ''
                            )
                        )
                    )
                );


            Logger::write(
                'ussd_provider_factory',
                [
                    'step' =>
                        'PROVIDER_SELECTION',

                    'provider' =>
                        $provider,
                ]
            );


            if ($provider === '') {

                throw new \RuntimeException(
                    'USSD provider is not configured.'
                );
            }


            return match ($provider) {

                'africastalking',
                'africa_talking',
                'africas_talking' =>
                    new AfricaTalkingUssdProvider(),

                'termii' =>
                    new TermiiUssdProvider(),

                'twilio' =>
                    new TwilioUssdProvider(),

                'arkesel' =>
                    new ArkeselUssdProvider(),

                default =>
                    throw new \RuntimeException(
                        "Unsupported USSD provider: {$provider}"
                    ),
            };
        }
        catch (Throwable $e) {

            Logger::write(
                'ussd_provider_factory_error',
                [
                    'step' =>
                        'FACTORY_FAILED',

                    'provider' =>
                        $provider
                        ?? null,

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );


            throw $e;
        }
    }


    /**
     * ---------------------------------------------------------
     * CHECK PROVIDER
     * ---------------------------------------------------------
     */
    public static function supports(
        string $provider
    ): bool {

        $provider =
            strtolower(
                trim(
                    $provider
                )
            );


        return in_array(
            $provider,
            [

                'africastalking',

                'africa_talking',

                'africas_talking',

                'termii',

                'twilio',

                'arkesel',

            ],
            true
        );
    }


    /**
     * ---------------------------------------------------------
     * LIST PROVIDERS
     * ---------------------------------------------------------
     */
    public static function providers(): array
    {
        return [

            'africastalking',

            'termii',

            'twilio',

            'arkesel',

        ];
    }
}
?>
