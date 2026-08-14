<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SENDAM BOT ENGINE
|--------------------------------------------------------------------------
|
| Application entry point.
|
| Responsibilities:
|
| - Load configuration
| - Register autoloader
| - Register global exception handling
| - Start the Bot application
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| PHP Error Handling
|--------------------------------------------------------------------------
*/

error_reporting(E_ALL);

ini_set(
    'display_errors',
    (defined('APP_DEBUG') && APP_DEBUG) ? '1' : '0'
);


/*
|--------------------------------------------------------------------------
| Base Path
|--------------------------------------------------------------------------
*/

define(
    'BASE_PATH',
    __DIR__
);


/*
|--------------------------------------------------------------------------
| Load Configuration
|--------------------------------------------------------------------------
*/

$configFile =
    BASE_PATH . '/config/config.php';


if (
    !file_exists(
        $configFile
    )
) {

    http_response_code(500);

    exit(
        'Configuration file missing.'
    );
}


require_once $configFile;


/*
|--------------------------------------------------------------------------
| Register Autoloader
|--------------------------------------------------------------------------
*/

$autoloaderFile =
    BASE_PATH . '/core/Autoloader.php';


if (
    !file_exists(
        $autoloaderFile
    )
) {

    http_response_code(500);

    exit(
        'Autoloader missing.'
    );
}


require_once $autoloaderFile;


/*
|--------------------------------------------------------------------------
| Start Autoloader
|--------------------------------------------------------------------------
*/

\Core\Autoloader::register();


/*
|--------------------------------------------------------------------------
| Global Exception Handler
|--------------------------------------------------------------------------
*/

set_exception_handler(
    function (Throwable $e): void {

        try {

            if (
                class_exists(
                    \Core\Logger::class
                )
            ) {

                \Core\Logger::write(
                    'fatal_exception',
                    [
                        'step' =>
                            'GLOBAL_EXCEPTION',

                        'message' =>
                            $e->getMessage(),

                        'file' =>
                            $e->getFile(),

                        'line' =>
                            $e->getLine(),

                        'trace' =>
                            $e->getTraceAsString(),

                        'request_uri' =>
                            $_SERVER['REQUEST_URI']
                            ?? null,

                        'method' =>
                            $_SERVER['REQUEST_METHOD']
                            ?? null,
                    ]
                );
            }
        }
        catch (Throwable) {

            /*
            |----------------------------------------------------------------------
            | Logging must never prevent the fatal response.
            |----------------------------------------------------------------------
            */
        }


        if (
            !headers_sent()
        ) {

            http_response_code(500);

            header(
                'Content-Type: text/plain; charset=utf-8'
            );
        }


        echo 'Application Error';
    }
);


/*
|--------------------------------------------------------------------------
| Global Shutdown Handler
|--------------------------------------------------------------------------
|
| Captures fatal PHP errors that cannot be caught by try/catch.
|
|--------------------------------------------------------------------------
*/

register_shutdown_function(
    function (): void {

        $error =
            error_get_last();


        if (
            !is_array($error)
        ) {

            return;
        }


        $fatalTypes = [

            E_ERROR,

            E_PARSE,

            E_CORE_ERROR,

            E_COMPILE_ERROR,

        ];


        if (
            !in_array(
                $error['type']
                ?? 0,
                $fatalTypes,
                true
            )
        ) {

            return;
        }


        try {

            if (
                class_exists(
                    \Core\Logger::class
                )
            ) {

                \Core\Logger::write(
                    'fatal_php_error',
                    [
                        'step' =>
                            'SHUTDOWN_FATAL_ERROR',

                        'message' =>
                            $error['message']
                            ?? null,

                        'file' =>
                            $error['file']
                            ?? null,

                        'line' =>
                            $error['line']
                            ?? null,
                    ]
                );
            }
        }
        catch (Throwable) {
        }
    }
);


/*
|--------------------------------------------------------------------------
| Start SENDAM Bot Engine
|--------------------------------------------------------------------------
*/

try {

    \Core\Logger::write(
        'bot_boot',
        [
            'step' =>
                'APPLICATION_START',

            'request_uri' =>
                $_SERVER['REQUEST_URI']
                ?? null,

            'method' =>
                $_SERVER['REQUEST_METHOD']
                ?? null,

            'time' =>
                date(
                    'Y-m-d H:i:s'
                ),
        ]
    );


    /*
    |--------------------------------------------------------------------------
    | Create Bot Application
    |--------------------------------------------------------------------------
    */

    $bot =
        new \Core\Bot();


    /*
    |--------------------------------------------------------------------------
    | Run Application
    |--------------------------------------------------------------------------
    */

    $bot->run();


    /*
    |--------------------------------------------------------------------------
    | Boot Complete
    |--------------------------------------------------------------------------
    */

    \Core\Logger::write(
        'bot_boot',
        [
            'step' =>
                'APPLICATION_COMPLETE',

            'request_uri' =>
                $_SERVER['REQUEST_URI']
                ?? null,
        ]
    );

}
catch (Throwable $e) {

    try {

        if (
            class_exists(
                \Core\Logger::class
            )
        ) {

            \Core\Logger::write(
                'bot_boot_error',
                [
                    'step' =>
                        'APPLICATION_STARTUP_FAILED',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),

                    'trace' =>
                        $e->getTraceAsString(),
                ]
            );
        }
    }
    catch (Throwable) {
    }


    if (
        !headers_sent()
    ) {

        http_response_code(500);

        header(
            'Content-Type: text/plain; charset=utf-8'
        );
    }


    echo 'Bot startup failed';
}
?>
