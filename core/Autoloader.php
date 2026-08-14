<?php

declare(strict_types=1);

namespace Core;

class Autoloader
{
    /**
     * ---------------------------------------------------------
     * Register SENDAM / PINGCHECKOUT Autoloader
     * ---------------------------------------------------------
     */
    public static function register(): void
    {
        spl_autoload_register(
            function (string $class): void {

                error_log(
                    'AUTOLOAD REQUEST: ' . $class
                );

                /*
                |--------------------------------------------------------------------------
                | Explicit File Overrides
                |--------------------------------------------------------------------------
                |
                | Some existing files use uppercase/lowercase naming that does not
                | exactly match the PSR-style class filename.
                |
                | Linux hosting is case-sensitive, so these files need explicit
                | mappings.
                |
                */

                $overrides = [

                    /*
                    |--------------------------------------------------------------
                    | SMS
                    |--------------------------------------------------------------
                    */

                    'Listeners\\Sms\\SmsListener' =>
                        \BASE_PATH . '/listeners/Sms/SMSListener.php',

                    'Listeners\\Sms\\SMSListener' =>
                        \BASE_PATH . '/listeners/Sms/SMSListener.php',

                    'Services\\SMS\\SmsService' =>
                        \BASE_PATH . '/services/Sms/SmsService.php',

                    /*
                    |--------------------------------------------------------------
                    | USSD
                    |--------------------------------------------------------------
                    |
                    | Folder is:
                    |
                    | listeners/Ussd/
                    |
                    */

                    'Listeners\\Ussd\\UssdListener' =>
                        \BASE_PATH . '/listeners/Ussd/UssdListener.php',

                    /*
                    |--------------------------------------------------------------
                    | Optional SMS Webhook Listener
                    |--------------------------------------------------------------
                    */

                    'Listeners\\Sms\\SmsWebhookListener' =>
                        \BASE_PATH . '/listeners/Sms/SmsWebhookListener.php',
                ];


                /*
                |--------------------------------------------------------------------------
                | Explicit Override
                |--------------------------------------------------------------------------
                */

                if (
                    isset(
                        $overrides[$class]
                    )
                ) {

                    $file =
                        $overrides[$class];


                    error_log(
                        'AUTOLOAD OVERRIDE FILE: ' . $file
                    );


                    if (
                        file_exists($file)
                    ) {

                        require_once $file;


                        error_log(
                            'AUTOLOAD OVERRIDE LOADED: ' . $file
                        );


                        return;
                    }


                    error_log(
                        'AUTOLOAD OVERRIDE MISSING: ' . $file
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | Namespace Map
                |--------------------------------------------------------------------------
                */

                $map = [

                    'Core\\' =>
                        \BASE_PATH . '/core/',

                    'Listeners\\' =>
                        \BASE_PATH . '/listeners/',

                    'Modules\\' =>
                        \BASE_PATH . '/modules/',

                    'Services\\' =>
                        \BASE_PATH . '/services/',

                    'Replies\\' =>
                        \BASE_PATH . '/replies/',

                    'Models\\' =>
                        \BASE_PATH . '/models/',

                    'Controllers\\' =>
                        \BASE_PATH . '/controllers/',
                ];


                /*
                |--------------------------------------------------------------------------
                | Resolve Namespace
                |--------------------------------------------------------------------------
                */

                foreach (
                    $map as $namespace => $directory
                ) {

                    if (
                        !str_starts_with(
                            $class,
                            $namespace
                        )
                    ) {

                        continue;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Remove Namespace Prefix
                    |--------------------------------------------------------------------------
                    */

                    $relative =
                        substr(
                            $class,
                            strlen($namespace)
                        );


                    /*
                    |--------------------------------------------------------------------------
                    | Convert Namespace To File Path
                    |--------------------------------------------------------------------------
                    */

                    $file =
                        $directory
                        .
                        str_replace(
                            '\\',
                            '/',
                            $relative
                        )
                        .
                        '.php';


                    error_log(
                        'AUTOLOAD FILE: ' . $file
                    );


                    /*
                    |--------------------------------------------------------------------------
                    | Standard File
                    |--------------------------------------------------------------------------
                    */

                    if (
                        file_exists($file)
                    ) {

                        require_once $file;


                        error_log(
                            'AUTOLOAD LOADED: ' . $file
                        );


                        return;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Case-Compatible Fallback
                    |--------------------------------------------------------------------------
                    |
                    | This is useful on Linux hosting where the physical
                    | filename may differ in capitalization from the class.
                    |
                    | Example:
                    |
                    | Class:
                    |     SmsListener
                    |
                    | Physical file:
                    |     SMSListener.php
                    |
                    */

                    $fallback =
                        self::findCaseInsensitiveFile(
                            $file
                        );


                    if (
                        $fallback !== null
                    ) {

                        error_log(
                            'AUTOLOAD CASE FALLBACK: ' . $fallback
                        );


                        require_once $fallback;


                        error_log(
                            'AUTOLOAD CASE LOADED: ' . $fallback
                        );


                        return;
                    }


                    error_log(
                        'AUTOLOAD MISSING FILE: ' . $file
                    );


                    /*
                    |--------------------------------------------------------------------------
                    | Continue Checking Other Namespaces
                    |--------------------------------------------------------------------------
                    */

                    return;
                }


                /*
                |--------------------------------------------------------------------------
                | Class Could Not Be Resolved
                |--------------------------------------------------------------------------
                */

                error_log(
                    'AUTOLOAD FAILED CLASS: ' . $class
                );
            }
        );
    }


    /**
     * ---------------------------------------------------------
     * Find File Ignoring Filename Case
     * ---------------------------------------------------------
     *
     * This is a safety fallback for Linux hosting where the
     * physical filename capitalization may not match the class
     * capitalization.
     */
    private static function findCaseInsensitiveFile(
        string $file
    ): ?string {

        /*
        |--------------------------------------------------------------------------
        | If Parent Directory Does Not Exist
        |--------------------------------------------------------------------------
        */

        $directory =
            dirname($file);


        if (
            !is_dir($directory)
        ) {

            return null;
        }


        /*
        |--------------------------------------------------------------------------
        | Expected Filename
        |--------------------------------------------------------------------------
        */

        $expected =
            basename($file);


        /*
        |--------------------------------------------------------------------------
        | Scan Directory
        |--------------------------------------------------------------------------
        */

        $files =
            scandir($directory);


        if (
            $files === false
        ) {

            return null;
        }


        foreach (
            $files as $filename
        ) {

            if (
                strcasecmp(
                    $filename,
                    $expected
                ) === 0
            ) {

                $candidate =
                    $directory
                    .
                    DIRECTORY_SEPARATOR
                    .
                    $filename;


                if (
                    is_file($candidate)
                ) {

                    return $candidate;
                }
            }
        }


        return null;
    }
}
