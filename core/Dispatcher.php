<?php

declare(strict_types=1);

namespace Core;

use ReflectionMethod;
use Throwable;

class Dispatcher
{
    /**
     * ---------------------------------------------------------
     * Dispatch Incoming HTTP Request
     * ---------------------------------------------------------
     */
    public function dispatch(): void
    {
        $requestId = $this->requestId();

        $path   = '/';
        $class  = null;
        $method = null;

        try {

            $this->log(
                'dispatcher',
                [
                    'step'       => 'DISPATCH_STARTED',
                    'request_id' => $requestId,
                    'method'     => $_SERVER['REQUEST_METHOD'] ?? null,
                    'uri'        => $_SERVER['REQUEST_URI'] ?? null,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Resolve Request Path
            |--------------------------------------------------------------------------
            */

            $uri =
                $_SERVER['REQUEST_URI']
                ?? '/';


            $parsedPath =
                parse_url(
                    $uri,
                    PHP_URL_PATH
                );


            if (
                !is_string($parsedPath)
                ||
                $parsedPath === ''
            ) {

                $path = '/';

            } else {

                $path = $parsedPath;
            }


            if ($path !== '/') {

                $path =
                    rtrim(
                        $path,
                        '/'
                    );
            }


            $this->log(
                'dispatcher',
                [
                    'step'       => 'PATH_RESOLVED',
                    'request_id' => $requestId,
                    'path'       => $path,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Load Routes
            |--------------------------------------------------------------------------
            */

            $routesFile =
                BASE_PATH . '/config/routes.php';


            if (
                !file_exists(
                    $routesFile
                )
            ) {

                $this->log(
                    'dispatcher_error',
                    [
                        'step'       => 'ROUTES_FILE_NOT_FOUND',
                        'request_id' => $requestId,
                        'file'       => $routesFile,
                    ]
                );


                $this->error(
                    'Routes configuration not found.',
                    500
                );

                return;
            }


            $routes =
                require $routesFile;


            if (
                !is_array($routes)
            ) {

                $this->log(
                    'dispatcher_error',
                    [
                        'step'       => 'INVALID_ROUTES_CONFIGURATION',
                        'request_id' => $requestId,
                    ]
                );


                $this->error(
                    'Invalid routes configuration.',
                    500
                );

                return;
            }


            $this->log(
                'dispatcher',
                [
                    'step'       => 'ROUTES_LOADED',
                    'request_id' => $requestId,
                    'route_count' => count($routes),
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Find Route
            |--------------------------------------------------------------------------
            */

            if (
                !array_key_exists(
                    $path,
                    $routes
                )
            ) {

                $this->log(
                    'dispatcher',
                    [
                        'step'       => 'ROUTE_NOT_FOUND',
                        'request_id' => $requestId,
                        'path'       => $path,
                    ]
                );


                $this->error(
                    'Route not found.',
                    404
                );

                return;
            }


            $route =
                $routes[$path];


            $this->log(
                'dispatcher',
                [
                    'step'       => 'ROUTE_FOUND',
                    'request_id' => $requestId,
                    'path'       => $path,
                    'route_type' => get_debug_type($route),
                    'route'      => $route,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Resolve Class + Method
            |--------------------------------------------------------------------------
            */

            [
                $class,
                $method
            ] =
                $this->resolveRoute(
                    $route,
                    $path,
                    $requestId
                );


            if (
                $class === null
                ||
                $method === null
            ) {

                return;
            }


            $this->log(
                'dispatcher',
                [
                    'step'       => 'ROUTE_RESOLVED',
                    'request_id' => $requestId,
                    'path'       => $path,
                    'class'      => $class,
                    'method'     => $method,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Verify Handler Class
            |--------------------------------------------------------------------------
            */

            $this->log(
                'dispatcher',
                [
                    'step'       => 'CHECKING_ROUTE_CLASS',
                    'request_id' => $requestId,
                    'class'      => $class,
                ]
            );


            if (
                !class_exists(
                    $class
                )
            ) {

                $this->log(
                    'dispatcher_error',
                    [
                        'step'       => 'ROUTE_CLASS_NOT_FOUND',
                        'request_id' => $requestId,
                        'class'      => $class,
                        'path'       => $path,
                    ]
                );


                $this->error(
                    "Route class '{$class}' not found.",
                    500
                );

                return;
            }


            $this->log(
                'dispatcher',
                [
                    'step'       => 'ROUTE_CLASS_FOUND',
                    'request_id' => $requestId,
                    'class'      => $class,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Create Handler
            |--------------------------------------------------------------------------
            */

            $this->log(
                'dispatcher',
                [
                    'step'       => 'CREATING_HANDLER',
                    'request_id' => $requestId,
                    'class'      => $class,
                ]
            );


            $instance =
                new $class();


            $this->log(
                'dispatcher',
                [
                    'step'       => 'HANDLER_CREATED',
                    'request_id' => $requestId,
                    'class'      => $class,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Verify Method
            |--------------------------------------------------------------------------
            */

            if (
                !method_exists(
                    $instance,
                    $method
                )
            ) {

                $this->log(
                    'dispatcher_error',
                    [
                        'step'       => 'ROUTE_METHOD_NOT_FOUND',
                        'request_id' => $requestId,
                        'class'      => $class,
                        'method'     => $method,
                        'path'       => $path,
                    ]
                );


                $this->error(
                    "Route handler '{$class}::{$method}' not found.",
                    500
                );

                return;
            }


            $this->log(
                'dispatcher',
                [
                    'step'       => 'ROUTE_METHOD_FOUND',
                    'request_id' => $requestId,
                    'class'      => $class,
                    'method'     => $method,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Execute Handler
            |--------------------------------------------------------------------------
            */

            $this->execute(
                $instance,
                $method,
                $requestId,
                $path
            );


            $this->log(
                'dispatcher',
                [
                    'step'       => 'DISPATCH_COMPLETE',
                    'request_id' => $requestId,
                    'path'       => $path,
                    'class'      => $class,
                    'method'     => $method,
                ]
            );

        }
        catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | IMPORTANT
            |--------------------------------------------------------------------------
            |
            | Do NOT allow Logger failure to hide the original exception.
            |
            */

            $this->log(
                'dispatcher_error',
                [
                    'step'       => 'DISPATCH_EXCEPTION',
                    'request_id' => $requestId,
                    'path'       => $path,
                    'class'      => $class,
                    'method'     => $method,

                    'exception_type' =>
                        get_class($e),

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


            $this->error(
                'Dispatcher error.',
                500
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * Resolve Route Definition
     * ---------------------------------------------------------
     */
    protected function resolveRoute(
        mixed $route,
        string $path,
        string $requestId
    ): array {

        /*
        |--------------------------------------------------------------------------
        | Listener Route
        |--------------------------------------------------------------------------
        */

        if (
            is_string($route)
        ) {

            return [
                $route,
                'handle',
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Controller Route
        |--------------------------------------------------------------------------
        */

        if (
            is_array($route)
            &&
            isset(
                $route[0],
                $route[1]
            )
            &&
            is_string($route[0])
            &&
            is_string($route[1])
        ) {

            return [
                $route[0],
                $route[1],
            ];
        }


        $this->log(
            'dispatcher_error',
            [
                'step'       => 'INVALID_ROUTE_DEFINITION',
                'request_id' => $requestId,
                'path'       => $path,
                'route'      => $route,
            ]
        );


        $this->error(
            "Invalid route definition: {$path}",
            500
        );


        return [
            null,
            null,
        ];
    }


    /**
     * ---------------------------------------------------------
     * Execute Handler
     * ---------------------------------------------------------
     */
    protected function execute(
        object $instance,
        string $method,
        string $requestId,
        string $path
    ): void {

        try {

            $class =
                $instance::class;


            $reflection =
                new ReflectionMethod(
                    $instance,
                    $method
                );


            $parameters =
                $reflection->getParameters();


            $this->log(
                'dispatcher',
                [
                    'step'       => 'HANDLER_EXECUTION_STARTED',
                    'request_id' => $requestId,
                    'path'       => $path,
                    'class'      => $class,
                    'method'     => $method,
                    'parameters' => count($parameters),
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Standard Listener
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | handle()
            |
            */

            if (
                count($parameters) === 0
            ) {

                $this->log(
                    'dispatcher',
                    [
                        'step'       => 'CALLING_HANDLER_WITHOUT_PAYLOAD',
                        'request_id' => $requestId,
                        'class'      => $class,
                        'method'     => $method,
                    ]
                );


                $instance->{$method}();


                $this->log(
                    'dispatcher',
                    [
                        'step'       => 'HANDLER_EXECUTION_COMPLETE',
                        'request_id' => $requestId,
                        'class'      => $class,
                        'method'     => $method,
                    ]
                );


                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Prepare Payload
            |--------------------------------------------------------------------------
            */

            $payload =
                $this->payload();


            $this->log(
                'dispatcher',
                [
                    'step'       => 'PAYLOAD_PREPARED',
                    'request_id' => $requestId,
                    'path'       => $path,
                    'keys'       => array_keys($payload),
                    'payload_count' => count($payload),
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | First Parameter
            |--------------------------------------------------------------------------
            */

            $firstParameter =
                $parameters[0];


            $type =
                $firstParameter->getType();


            /*
            |--------------------------------------------------------------------------
            | Array Parameter
            |--------------------------------------------------------------------------
            */

            if (
                $type !== null
                &&
                $type->getName() === 'array'
            ) {

                $instance->{$method}(
                    $payload
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Untyped Parameter
            |--------------------------------------------------------------------------
            */

            if (
                $type === null
            ) {

                $instance->{$method}(
                    $payload
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Unsupported Parameter
            |--------------------------------------------------------------------------
            */

            $this->log(
                'dispatcher_error',
                [
                    'step'       => 'UNSUPPORTED_HANDLER_PARAMETER',
                    'request_id' => $requestId,
                    'class'      => $class,
                    'method'     => $method,
                    'parameter'  => $firstParameter->getName(),
                    'type'       => $type->getName(),
                ]
            );


            $this->error(
                'Unable to invoke route handler.',
                500
            );
        }
        catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | Log Handler Failure
            |--------------------------------------------------------------------------
            */

            $this->log(
                'dispatcher_error',
                [
                    'step'       => 'HANDLER_EXECUTION_FAILED',
                    'request_id' => $requestId,
                    'path'       => $path,
                    'class'      => $instance::class,
                    'method'     => $method,

                    'exception_type' =>
                        get_class($e),

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


            /*
            |--------------------------------------------------------------------------
            | Do NOT Throw Again
            |--------------------------------------------------------------------------
            */

            $this->error(
                'Unable to execute route handler.',
                500
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * Read Request Payload
     * ---------------------------------------------------------
     */
    protected function payload(): array
    {
        $contentType =
            strtolower(
                (string)(
                    $_SERVER['CONTENT_TYPE']
                    ?? ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | JSON
        |--------------------------------------------------------------------------
        */

        if (
            str_contains(
                $contentType,
                'application/json'
            )
        ) {

            $raw =
                file_get_contents(
                    'php://input'
                );


            if (
                !is_string($raw)
                ||
                trim($raw) === ''
            ) {

                return [];
            }


            $decoded =
                json_decode(
                    $raw,
                    true
                );


            return
                is_array($decoded)
                    ? $decoded
                    : [];
        }


        /*
        |--------------------------------------------------------------------------
        | Standard POST
        |--------------------------------------------------------------------------
        */

        if (
            is_array($_POST)
        ) {

            return $_POST;
        }


        return [];
    }


    /**
     * ---------------------------------------------------------
     * Safe Logger
     * ---------------------------------------------------------
     *
     * Logging must NEVER break the request.
     */
    protected function log(
        string $channel,
        array $data = []
    ): void {

        try {

            if (
                class_exists(
                    Logger::class
                )
            ) {

                Logger::write(
                    $channel,
                    $data
                );

                return;
            }

        }
        catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | Fallback PHP Error Log
            |--------------------------------------------------------------------------
            */

            error_log(
                'DISPATCHER LOGGER FAILURE: '
                .
                $e->getMessage()
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Fallback
        |--------------------------------------------------------------------------
        */

        error_log(
            strtoupper($channel)
            .
            ': '
            .
            json_encode(
                $data,
                JSON_UNESCAPED_UNICODE
                |
                JSON_UNESCAPED_SLASHES
            )
        );
    }


    /**
     * ---------------------------------------------------------
     * HTTP Error Response
     * ---------------------------------------------------------
     */
    protected function error(
        string $message,
        int $status
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Prevent Duplicate Headers
        |--------------------------------------------------------------------------
        */

        if (
            !headers_sent()
        ) {

            http_response_code(
                $status
            );


            header(
                'Content-Type: application/json; charset=utf-8'
            );
        }


        echo json_encode(
            [
                'success' => false,
                'message' => $message,
            ],
            JSON_UNESCAPED_UNICODE
            |
            JSON_UNESCAPED_SLASHES
        );
    }


    /**
     * ---------------------------------------------------------
     * Request ID
     * ---------------------------------------------------------
     */
    protected function requestId(): string
    {
        try {

            return bin2hex(
                random_bytes(8)
            );

        }
        catch (Throwable) {

            return uniqid(
                'req_',
                true
            );
        }
    }
}
