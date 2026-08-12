<?php

declare(strict_types=1);

namespace Listeners\Api;

use Core\Logger;
use Services\Escrow\EscrowApiService;
use Throwable;

class EscrowApiListener
{
    protected EscrowApiService $service;

    public function __construct()
    {
        $this->service = new EscrowApiService();

        Logger::write(
            'escrow_api_listener',
            [
                'step' => 'CONSTRUCTOR'
            ]
        );
    }

    /**
     * ---------------------------------------------------------
     * Main API Handler
     * ---------------------------------------------------------
     */
    public function handle(): void
    {
        try {

            Logger::write(
                'escrow_api_listener',
                [
                    'step' => 'HANDLE_START',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                    'uri' => $_SERVER['REQUEST_URI'] ?? null,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null
                ]
            );

            $method = strtoupper(
                $_SERVER['REQUEST_METHOD'] ?? 'GET'
            );

            $path = parse_url(
                $_SERVER['REQUEST_URI'] ?? '/',
                PHP_URL_PATH
            );

            $path = is_string($path)
                ? rtrim($path, '/')
                : '';

            if ($path === '') {
                $path = '/';
            }

            /*
            |--------------------------------------------------------------------------
            | Only POST is accepted
            |--------------------------------------------------------------------------
            */

            if ($method !== 'POST') {

                $this->respond(
                    405,
                    [
                        'success' => false,
                        'message' => 'Method not allowed.'
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Read JSON Request
            |--------------------------------------------------------------------------
            */

            $input = $this->requestData();

            Logger::write(
                'escrow_api_listener',
                [
                    'step' => 'REQUEST_RECEIVED',
                    'path' => $path,
                    'input' => $this->safeLogInput($input)
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Route API Operation
            |--------------------------------------------------------------------------
            */

            switch ($path) {

                case '/api/escrow/verify':

                    $this->verify($input);

                    return;


                case '/api/escrow/release':

                    $this->release($input);

                    return;


                default:

                    $this->respond(
                        404,
                        [
                            'success' => false,
                            'message' => 'Escrow API endpoint not found.'
                        ]
                    );

                    return;
            }

        } catch (Throwable $e) {

            Logger::write(
                'escrow_api_listener_error',
                [
                    'step' => 'HANDLE_EXCEPTION',
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );

            $this->respond(
                500,
                [
                    'success' => false,
                    'message' => 'Internal server error.'
                ]
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * Verify Escrow
     * ---------------------------------------------------------
     *
     * POST /api/escrow/verify
     *
     * {
     *     "reference": "SDM-000033"
     * }
     *
     * ---------------------------------------------------------
     */
    protected function verify(
        array $input
    ): void {

        try {

            Logger::write(
                'escrow_api_listener',
                [
                    'step' => 'VERIFY_START'
                ]
            );

            $reference = trim(
                (string)(
                    $input['reference']
                    ?? ''
                )
            );

            if ($reference === '') {

                $this->respond(
                    422,
                    [
                        'success' => false,
                        'message' => 'Escrow reference is required.'
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Service
            |--------------------------------------------------------------------------
            */

            $result =
                $this->service->verify(
                    $reference
                );

            Logger::write(
                'escrow_api_listener',
                [
                    'step' => 'VERIFY_SERVICE_RESULT',
                    'reference' => strtoupper($reference),
                    'result' => $result
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | HTTP Status
            |--------------------------------------------------------------------------
            */

            if (
                !($result['success'] ?? false)
            ) {

                $this->respond(
                    404,
                    $result
                );

                return;
            }

            $this->respond(
                200,
                $result
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_api_listener_error',
                [
                    'step' => 'VERIFY_EXCEPTION',
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            );

            $this->respond(
                500,
                [
                    'success' => false,
                    'message' => 'Unable to verify escrow.'
                ]
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * Release / Confirm Receipt
     * ---------------------------------------------------------
     *
     * POST /api/escrow/release
     *
     * {
     *     "reference": "SDM-000033",
     *     "phone": "08012345678"
     * }
     *
     * ---------------------------------------------------------
     *
     * IMPORTANT:
     *
     * This does NOT directly pay the seller.
     *
     * It means:
     *
     * BUYER CONFIRMED RECEIPT
     *
     * The existing escrow confirmation workflow then
     * continues from EscrowConfirmationService.
     *
     * ---------------------------------------------------------
     */
    protected function release(
        array $input
    ): void {

        try {

            Logger::write(
                'escrow_api_listener',
                [
                    'step' => 'RELEASE_START'
                ]
            );

            $reference = trim(
                (string)(
                    $input['reference']
                    ?? ''
                )
            );

            $phone = trim(
                (string)(
                    $input['phone']
                    ?? ''
                )
            );

            /*
            |--------------------------------------------------------------------------
            | Validate Reference
            |--------------------------------------------------------------------------
            */

            if ($reference === '') {

                $this->respond(
                    422,
                    [
                        'success' => false,
                        'message' => 'Escrow reference is required.'
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Phone
            |--------------------------------------------------------------------------
            */

            if ($phone === '') {

                $this->respond(
                    422,
                    [
                        'success' => false,
                        'message' => 'Buyer phone number is required.'
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Confirm Receipt
            |--------------------------------------------------------------------------
            |
            | EscrowApiService performs:
            |
            | reference lookup
            | buyer lookup
            | phone normalization
            | buyer authorization
            | existing escrow confirmation workflow
            |
            */

            $result =
                $this->service->confirmReceipt(
                    $reference,
                    $phone
                );

            Logger::write(
                'escrow_api_listener',
                [
                    'step' => 'RELEASE_SERVICE_RESULT',
                    'reference' => strtoupper($reference),
                    'result' => $result
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Determine HTTP Response
            |--------------------------------------------------------------------------
            */

            if (
                !($result['success'] ?? false)
            ) {

                /*
                |--------------------------------------------------------------------------
                | Authorization Failure
                |--------------------------------------------------------------------------
                */

                $message =
                    strtolower(
                        (string)(
                            $result['message']
                            ?? ''
                        )
                    );

                if (
                    str_contains(
                        $message,
                        'not authorized'
                    )
                    ||
                    str_contains(
                        $message,
                        'not registered'
                    )
                ) {

                    $this->respond(
                        403,
                        $result
                    );

                    return;
                }

                /*
                |--------------------------------------------------------------------------
                | General Business Failure
                |--------------------------------------------------------------------------
                */

                $this->respond(
                    422,
                    $result
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Success
            |--------------------------------------------------------------------------
            */

            $this->respond(
                200,
                $result
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_api_listener_error',
                [
                    'step' => 'RELEASE_EXCEPTION',
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );

            $this->respond(
                500,
                [
                    'success' => false,
                    'message' => 'Unable to confirm receipt.'
                ]
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * Read Request Body
     * ---------------------------------------------------------
     */
    protected function requestData(): array
    {
        $raw = file_get_contents('php://input');

        if (
            !is_string($raw)
            ||
            trim($raw) === ''
        ) {

            /*
            |--------------------------------------------------------------------------
            | Allow form-urlencoded / standard POST
            |--------------------------------------------------------------------------
            */

            return is_array($_POST)
                ? $_POST
                : [];
        }

        /*
        |--------------------------------------------------------------------------
        | Attempt JSON
        |--------------------------------------------------------------------------
        */

        $json = json_decode(
            $raw,
            true
        );

        if (
            is_array($json)
        ) {

            return $json;
        }

        /*
        |--------------------------------------------------------------------------
        | Fallback to POST
        |--------------------------------------------------------------------------
        */

        return is_array($_POST)
            ? $_POST
            : [];
    }


    /**
     * ---------------------------------------------------------
     * JSON Response
     * ---------------------------------------------------------
     */
    protected function respond(
        int $status,
        array $data
    ): void {

        http_response_code(
            $status
        );

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
            |
            JSON_UNESCAPED_SLASHES
        );

    }


    /**
     * ---------------------------------------------------------
     * Safe Logging
     * ---------------------------------------------------------
     *
     * Never log sensitive credentials.
     */
    protected function safeLogInput(
        array $input
    ): array {

        $safe = $input;

        /*
        |--------------------------------------------------------------------------
        | Never log authentication credentials
        |--------------------------------------------------------------------------
        */

        unset(
            $safe['api_key'],
            $safe['authorization'],
            $safe['token'],
            $safe['secret'],
            $safe['password']
        );

        return $safe;
    }
}