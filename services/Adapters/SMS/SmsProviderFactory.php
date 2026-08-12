<?php

declare(strict_types=1);

namespace Services\Adapters\SMS;

use Core\Logger;
use RuntimeException;
use Throwable;

class SmsProviderFactory
{
    /**
     * ---------------------------------------------------------
     * Create Active SMS Provider
     * ---------------------------------------------------------
     *
     * Provider is selected from configuration:
     *
     * SMS_PROVIDER=twilio
     * SMS_PROVIDER=termii
     * SMS_PROVIDER=africas_talking
     * SMS_PROVIDER=arkesel
     *
     * The configuration constant is preferred because the
     * existing bot engine uses config/config.php constants.
     */
    public static function make(): SmsProviderInterface
    {
        $provider =
            self::configuredProvider();


        Logger::write(
            'sms_provider_factory',
            [
                'step' =>
                    'PROVIDER_SELECTION',

                'provider' =>
                    $provider
            ]
        );


        try {

            switch ($provider) {

                case 'twilio':

                    return new TwilioSmsProvider();


                case 'termii':

                    return new TermiiSmsProvider();


                case 'africas_talking':

                case 'africastalking':

                case 'africa_talking':

                    return new AfricasTalkingSmsProvider();


                case 'arkesel':

                    return new ArkeselSmsProvider();


                default:

                    throw new RuntimeException(
                        "Unsupported SMS provider: {$provider}"
                    );
            }

        }
        catch (Throwable $e) {

            Logger::write(
                'sms_provider_factory_error',
                [
                    'step' =>
                        'PROVIDER_CREATION_FAILED',

                    'provider' =>
                        $provider,

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine()
                ]
            );


            throw $e;
        }
    }


    /**
     * ---------------------------------------------------------
     * Get Configured Provider
     * ---------------------------------------------------------
     */
    protected static function configuredProvider(): string
    {
        /*
        |--------------------------------------------------------------------------
        | Preferred constant
        |--------------------------------------------------------------------------
        */

        if (
            defined('SMS_PROVIDER')
        ) {

            $provider =
                strtolower(
                    trim(
                        (string)SMS_PROVIDER
                    )
                );

        }
        else {

            /*
            |--------------------------------------------------------------------------
            | Environment fallback
            |--------------------------------------------------------------------------
            */

            $provider =
                strtolower(
                    trim(
                        (string)(
                            getenv('SMS_PROVIDER')
                            ?: ''
                        )
                    )
                );
        }


        /*
        |--------------------------------------------------------------------------
        | Default Provider
        |--------------------------------------------------------------------------
        |
        | Twilio is used as the code-level fallback because it supports
        | inbound messaging/webhooks and outbound messaging.
        |
        | The admin/config value should normally override this.
        |
        */

        if (
            $provider === ''
        ) {

            $provider = 'twilio';
        }


        /*
        |--------------------------------------------------------------------------
        | Normalize Provider Names
        |--------------------------------------------------------------------------
        */

        return match ($provider) {

            'twilio',
            'twilio_sms'
                =>
                'twilio',

            'termii'
                =>
                'termii',

            'africas_talking',
            'africastalking',
            'africa_talking',
            'africas-talking'
                =>
                'africas_talking',

            'arkesel'
                =>
                'arkesel',

            default
                =>
                $provider
        };
    }


    /**
     * ---------------------------------------------------------
     * Get Active Provider Name
     * ---------------------------------------------------------
     */
    public static function providerName(): string
    {
        return self::configuredProvider();
    }


    /**
     * ---------------------------------------------------------
     * Check Supported Provider
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
                'twilio',
                'termii',
                'africas_talking',
                'africastalking',
                'africa_talking',
                'africas-talking',
                'arkesel'
            ],
            true
        );
    }
}
