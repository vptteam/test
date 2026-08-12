```php
<?php

declare(strict_types=1);

/**
 * --------------------------------------------------------------------------
 * SENDAM ADMIN ESCROW PAYOUT ENTRY POINT
 * --------------------------------------------------------------------------
 *
 * File:
 *
 *     /admin/escrow_payout.php
 *
 * Responsibility:
 *
 *     1. Define BASE_PATH
 *     2. Load application configuration
 *     3. Register the SENDAM autoloader
 *     4. Validate AdminEscrowListener
 *     5. Execute AdminEscrowListener
 *
 * All escrow administration logic remains inside:
 *
 *     Listeners/Admin/AdminEscrowListener.php
 *
 * --------------------------------------------------------------------------
 */


/*
|--------------------------------------------------------------------------
| BASE PATH
|--------------------------------------------------------------------------
|
| This file lives inside:
|
|     /admin/escrow_payout.php
|
| Therefore the project root is one directory above /admin.
|
*/

if (!defined('BASE_PATH')) {

    define(
        'BASE_PATH',
        dirname(__DIR__)
    );
}


/*
|--------------------------------------------------------------------------
| SESSION SECURITY
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {

    session_set_cookie_params([
        'httponly' => true,

        'secure' => (
            !empty($_SERVER['HTTPS'])
            &&
            $_SERVER['HTTPS'] !== 'off'
        ),

        'samesite' => 'Strict',

    ]);

    session_start();
}


/*
|--------------------------------------------------------------------------
| LOAD APPLICATION CONFIGURATION
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| config.php defines:
|
|     DB_HOST
|     DB_NAME
|     DB_USER
|     DB_PASS
|
| It also defines the other SENDAM application constants.
|
*/

$configFile =
    BASE_PATH . '/config/config.php';


if (!is_file($configFile)) {

    http_response_code(500);

    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';

    echo '<meta charset="UTF-8">';

    echo '<meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >';

    echo '<title>SENDAM Admin Error</title>';

    echo '<style>

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #0b1020;
            color: #ffffff;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }

        .error {
            width: 100%;
            max-width: 650px;
            padding: 30px;
            background: #151c31;
            border: 1px solid #743042;
            border-radius: 14px;
        }

        h1 {
            margin-top: 0;
            color: #ffb6c1;
        }

        p {
            color: #cbd5e1;
            line-height: 1.6;
        }

        code {
            color: #ffffff;
            background: #0d1427;
            padding: 3px 6px;
            border-radius: 5px;
        }

    </style>';

    echo '</head>';
    echo '<body>';

    echo '<div class="error">';

    echo '<h1>SENDAM Admin Error</h1>';

    echo '<p>';
    echo 'Application configuration could not be loaded.';
    echo '</p>';

    echo '<p>';
    echo 'Expected file: ';
    echo '<code>config/config.php</code>';
    echo '</p>';

    echo '</div>';

    echo '</body>';
    echo '</html>';

    exit;
}


try {

    require_once $configFile;

} catch (\Throwable $e) {

    http_response_code(500);

    error_log(
        'SENDAM Config Load Error: '
        . $e->getMessage()
        . PHP_EOL
        . $e->getTraceAsString()
    );

    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';

    echo '<meta charset="UTF-8">';

    echo '<meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >';

    echo '<title>SENDAM Admin Error</title>';

    echo '</head>';

    echo '<body>';

    echo '<h1>SENDAM Admin Error</h1>';

    echo '<p>';
    echo 'Unable to load application configuration.';
    echo '</p>';

    echo '</body>';

    echo '</html>';

    exit;
}


/*
|--------------------------------------------------------------------------
| VALIDATE DATABASE CONFIGURATION
|--------------------------------------------------------------------------
|
| Do not display the actual credentials.
|
*/

$requiredConstants = [
    'DB_HOST',
    'DB_NAME',
    'DB_USER',
    'DB_PASS',
];


foreach ($requiredConstants as $constant) {

    if (!defined($constant)) {

        http_response_code(500);

        error_log(
            'SENDAM Admin Escrow Missing Configuration: '
            . $constant
        );

        echo '<!DOCTYPE html>';
        echo '<html lang="en">';
        echo '<head>';

        echo '<meta charset="UTF-8">';

        echo '<meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
        >';

        echo '<title>SENDAM Admin Error</title>';

        echo '<style>

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
                background: #0b1020;
                color: #ffffff;
                font-family: Arial, Helvetica, sans-serif;
            }

            .error {
                width: 100%;
                max-width: 650px;
                padding: 30px;
                background: #151c31;
                border: 1px solid #743042;
                border-radius: 14px;
            }

            h1 {
                margin-top: 0;
                color: #ffb6c1;
            }

            p {
                color: #cbd5e1;
                line-height: 1.6;
            }

            code {
                color: #ffffff;
                background: #0d1427;
                padding: 3px 6px;
                border-radius: 5px;
            }

        </style>';

        echo '</head>';
        echo '<body>';

        echo '<div class="error">';

        echo '<h1>SENDAM Admin Error</h1>';

        echo '<p>';
        echo 'Required application configuration is missing.';
        echo '</p>';

        echo '<p>';
        echo 'Missing configuration: ';
        echo '<code>'
            . htmlspecialchars(
                $constant,
                ENT_QUOTES,
                'UTF-8'
            )
            . '</code>';
        echo '</p>';

        echo '</div>';

        echo '</body>';
        echo '</html>';

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| LOAD AUTOLOADER
|--------------------------------------------------------------------------
*/

$autoloaderFile =
    BASE_PATH . '/core/Autoloader.php';


if (!is_file($autoloaderFile)) {

    http_response_code(500);

    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';

    echo '<meta charset="UTF-8">';

    echo '<meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >';

    echo '<title>SENDAM Admin Error</title>';

    echo '</head>';

    echo '<body>';

    echo '<h1>SENDAM Admin Error</h1>';

    echo '<p>';
    echo 'Core autoloader could not be found.';
    echo '</p>';

    echo '<p>';
    echo '<code>core/Autoloader.php</code>';
    echo '</p>';

    echo '</body>';

    echo '</html>';

    exit;
}


try {

    require_once $autoloaderFile;

} catch (\Throwable $e) {

    http_response_code(500);

    error_log(
        'SENDAM Admin Autoloader Error: '
        . $e->getMessage()
        . PHP_EOL
        . $e->getTraceAsString()
    );

    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';

    echo '<meta charset="UTF-8">';

    echo '<meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >';

    echo '<title>SENDAM Admin Error</title>';

    echo '</head>';

    echo '<body>';

    echo '<h1>SENDAM Admin Error</h1>';

    echo '<p>';
    echo 'Unable to load the SENDAM autoloader.';
    echo '</p>';

    echo '</body>';

    echo '</html>';

    exit;
}


/*
|--------------------------------------------------------------------------
| REGISTER AUTOLOADER
|--------------------------------------------------------------------------
|
| Your Autoloader class exposes:
|
|     Autoloader::register()
|
*/

if (
    class_exists(\Core\Autoloader::class)
    &&
    method_exists(
        \Core\Autoloader::class,
        'register'
    )
) {

    \Core\Autoloader::register();

}


/*
|--------------------------------------------------------------------------
| ADMIN ESCROW LISTENER
|--------------------------------------------------------------------------
*/

$listenerClass =
    \Listeners\Admin\AdminEscrowListener::class;


/*
|--------------------------------------------------------------------------
| VERIFY LISTENER CLASS
|--------------------------------------------------------------------------
*/

if (!class_exists($listenerClass)) {

    http_response_code(500);

    error_log(
        'SENDAM Admin Escrow Listener Missing: '
        . $listenerClass
    );

    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';

    echo '<meta charset="UTF-8">';

    echo '<meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >';

    echo '<title>SENDAM Admin Error</title>';

    echo '<style>

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #0b1020;
            color: #ffffff;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }

        .error {
            width: 100%;
            max-width: 650px;
            padding: 30px;
            background: #151c31;
            border: 1px solid #743042;
            border-radius: 14px;
        }

        h1 {
            margin-top: 0;
            color: #ffb6c1;
        }

        p {
            color: #cbd5e1;
            line-height: 1.6;
        }

        code {
            color: #ffffff;
            background: #0d1427;
            padding: 3px 6px;
            border-radius: 5px;
        }

    </style>';

    echo '</head>';
    echo '<body>';

    echo '<div class="error">';

    echo '<h1>SENDAM Admin Error</h1>';

    echo '<p>';
    echo 'The Admin Escrow listener could not be loaded.';
    echo '</p>';

    echo '<p>';
    echo 'Expected: ';
    echo '<code>'
        . htmlspecialchars(
            $listenerClass,
            ENT_QUOTES,
            'UTF-8'
        )
        . '</code>';
    echo '</p>';

    echo '<p>';
    echo 'Expected file: ';
    echo '<code>';
    echo 'listeners/Admin/AdminEscrowListener.php';
    echo '</code>';
    echo '</p>';

    echo '</div>';

    echo '</body>';
    echo '</html>';

    exit;
}


/*
|--------------------------------------------------------------------------
| VERIFY HANDLE METHOD
|--------------------------------------------------------------------------
*/

if (!method_exists($listenerClass, 'handle')) {

    http_response_code(500);

    error_log(
        'SENDAM Admin Escrow Listener Missing handle(): '
        . $listenerClass
    );

    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';

    echo '<meta charset="UTF-8">';

    echo '<meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >';

    echo '<title>SENDAM Admin Error</title>';

    echo '</head>';

    echo '<body>';

    echo '<h1>SENDAM Admin Error</h1>';

    echo '<p>';
    echo 'AdminEscrowListener does not implement handle().';
    echo '</p>';

    echo '</body>';

    echo '</html>';

    exit;
}


/*
|--------------------------------------------------------------------------
| CREATE LISTENER
|--------------------------------------------------------------------------
*/

try {

    $listener =
        new $listenerClass();

} catch (\Throwable $e) {

    http_response_code(500);

    error_log(
        'SENDAM Admin Escrow Listener Initialization Error: '
        . $e->getMessage()
        . PHP_EOL
        . $e->getTraceAsString()
    );

    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';

    echo '<meta charset="UTF-8">';

    echo '<meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >';

    echo '<title>SENDAM Admin Error</title>';

    echo '</head>';

    echo '<body>';

    echo '<h1>SENDAM Admin Error</h1>';

    echo '<p>';
    echo 'Unable to initialize the Admin Escrow listener.';
    echo '</p>';

    echo '</body>';

    echo '</html>';

    exit;
}


/*
|--------------------------------------------------------------------------
| EXECUTE LISTENER
|--------------------------------------------------------------------------
*/

try {

    $listener->handle();

} catch (\Throwable $e) {

    http_response_code(500);

    error_log(
        'SENDAM Admin Escrow Listener Error: '
        . $e->getMessage()
        . PHP_EOL
        . $e->getTraceAsString()
    );

    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';

    echo '<meta charset="UTF-8">';

    echo '<meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >';

    echo '<title>SENDAM Admin Error</title>';

    echo '<style>

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #0b1020;
            color: #ffffff;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }

        .error {
            width: 100%;
            max-width: 650px;
            padding: 30px;
            background: #151c31;
            border: 1px solid #743042;
            border-radius: 14px;
        }

        h1 {
            margin-top: 0;
            color: #ffb6c1;
        }

        p {
            color: #cbd5e1;
            line-height: 1.6;
        }

    </style>';

    echo '</head>';
    echo '<body>';

    echo '<div class="error">';

    echo '<h1>SENDAM Escrow Admin</h1>';

    echo '<p>';
    echo 'An unexpected error occurred while loading the escrow administration page.';
    echo '</p>';

    echo '</div>';

    echo '</body>';
    echo '</html>';

    exit;
}
```
