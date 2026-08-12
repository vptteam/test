<?php

declare(strict_types=1);

namespace Listeners\Payments;

use Controllers\AdvertPaymentController;
use Core\Logger;
use Services\Escrow\PaystackEscrowWebhookService;
use Throwable;

class PaystackWebhookListener
{
    /**
     * ---------------------------------------------------------
     * Handle Paystack Webhook
     * ---------------------------------------------------------
     *
     * Canonical route:
     *
     * POST /payment/paystack/advert/webhook
     *
     * Responsibilities:
     *
     * 1. Read raw Paystack payload once.
     * 2. Verify Paystack signature.
     * 3. Decode JSON once.
     * 4. Validate webhook envelope.
     * 5. Route escrow payments away from advert processing.
     * 6. Pass decoded advert payload to AdvertPaymentController.
     *
     * ---------------------------------------------------------
     */
    public function handle(): void
    {
        $raw = '';

        try {

            /*
            |--------------------------------------------------------------------------
            | Request Information
            |--------------------------------------------------------------------------
            */

            $method =
                $_SERVER['REQUEST_METHOD']
                ?? '';

            $uri =
                $_SERVER['REQUEST_URI']
                ?? '';

            $signature =
                $_SERVER['HTTP_X_PAYSTACK_SIGNATURE']
                ?? '';

            /*
            |--------------------------------------------------------------------------
            | Read Raw Body ONCE
            |--------------------------------------------------------------------------
            */

            $raw =
                file_get_contents(
                    'php://input'
                );

            if (!is_string($raw)) {
                $raw = '';
            }

            Logger::write(
                'paystack_webhook_listener',
                [
                    'step' =>
                        'REQUEST_RECEIVED',

                    'method' =>
                        $method,

                    'uri' =>
                        $uri,

                    'signature_present' =>
                        $signature !== '',

                    'payload_length' =>
                        strlen($raw),

                    'payload' =>
                        $raw,

                    'time' =>
                        date('Y-m-d H:i:s')
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Validate HTTP Method
            |--------------------------------------------------------------------------
            */

            if (
                strtoupper($method)
                !==
                'POST'
            ) {

                Logger::write(
                    'paystack_webhook_error',
                    [
                        'step' =>
                            'INVALID_METHOD',

                        'method' =>
                            $method
                    ]
                );

                http_response_code(405);

                echo 'Method Not Allowed';

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Payload Exists
            |--------------------------------------------------------------------------
            */

            if (
                trim($raw)
                ===
                ''
            ) {

                Logger::write(
                    'paystack_webhook_error',
                    [
                        'step' =>
                            'EMPTY_PAYLOAD'
                    ]
                );

                http_response_code(400);

                echo 'Invalid payload';

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Paystack Signature
            |--------------------------------------------------------------------------
            */

            if (
                $signature === ''
            ) {

                Logger::write(
                    'paystack_webhook_error',
                    [
                        'step' =>
                            'SIGNATURE_MISSING'
                    ]
                );

                http_response_code(401);

                echo 'Invalid signature';

                return;
            }


            $expectedSignature =
                hash_hmac(
                    'sha512',
                    $raw,
                    PAYSTACK_SECRET_KEY
                );


            if (
                !hash_equals(
                    $expectedSignature,
                    $signature
                )
            ) {

                Logger::write(
                    'paystack_webhook_error',
                    [
                        'step' =>
                            'INVALID_SIGNATURE'
                    ]
                );

                http_response_code(401);

                echo 'Invalid signature';

                return;
            }


            Logger::write(
                'paystack_webhook_listener',
                [
                    'step' =>
                        'SIGNATURE_VALID'
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Decode JSON ONCE
            |--------------------------------------------------------------------------
            */

            $payload =
                json_decode(
                    $raw,
                    true
                );


            if (
                !is_array($payload)
            ) {

                Logger::write(
                    'paystack_webhook_error',
                    [
                        'step' =>
                            'INVALID_JSON',

                        'json_error' =>
                            json_last_error_msg()
                    ]
                );

                http_response_code(400);

                echo 'Invalid JSON';

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Paystack Envelope
            |--------------------------------------------------------------------------
            */

            $event =
                trim(
                    (string)(
                        $payload['event']
                        ?? ''
                    )
                );


            $data =
                $payload['data']
                ??
                null;


            if (
                $event === ''
                ||
                !is_array($data)
            ) {

                Logger::write(
                    'paystack_webhook_error',
                    [
                        'step' =>
                            'INVALID_PAYLOAD_STRUCTURE',

                        'event' =>
                            $event,

                        'has_data' =>
                            is_array($data)
                    ]
                );

                http_response_code(400);

                echo 'Invalid payload';

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Extract Reference
            |--------------------------------------------------------------------------
            */

            $reference =
                trim(
                    (string)(
                        $data['reference']
                        ?? ''
                    )
                );


            Logger::write(
                'paystack_webhook_listener',
                [
                    'step' =>
                        'PAYLOAD_DECODED',

                    'event' =>
                        $event,

                    'reference' =>
                        $reference,

                    'status' =>
                        $data['status']
                        ?? null
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Paystack Event
            |--------------------------------------------------------------------------
            |
            | We only activate payments for charge.success.
            |
            | Other valid Paystack events are acknowledged.
            |
            */

            if (
                $event
                !==
                'charge.success'
            ) {

                Logger::write(
                    'paystack_webhook_listener',
                    [
                        'step' =>
                            'EVENT_IGNORED',

                        'event' =>
                            $event,

                        'reference' =>
                            $reference
                    ]
                );

                http_response_code(200);

                echo 'OK';

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Reference
            |--------------------------------------------------------------------------
            */

            if (
                $reference === ''
            ) {

                Logger::write(
                    'paystack_webhook_error',
                    [
                        'step' =>
                            'REFERENCE_MISSING',

                        'event' =>
                            $event
                    ]
                );

                /*
                 * The webhook itself is valid, but it cannot be processed.
                 * Acknowledge it to prevent Paystack from endlessly retrying
                 * malformed application data.
                 */

                http_response_code(200);

                echo 'Missing reference';

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Metadata
            |--------------------------------------------------------------------------
            */

            $metadata =
                $data['metadata']
                ??
                [];


            if (
                !is_array($metadata)
            ) {

                $metadata = [];
            }


            $paymentType =
                strtolower(
                    trim(
                        (string)(
                            $metadata['type']
                            ?? ''
                        )
                    )
                );


            Logger::write(
                'paystack_webhook_listener',
                [
                    'step' =>
                        'PAYMENT_TYPE_DETECTED',

                    'reference' =>
                        $reference,

                    'type' =>
                        $paymentType,

                    'metadata' =>
                        $metadata
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | ESCROW
            |--------------------------------------------------------------------------
            |
            | Escrow has its own canonical webhook:
            |
            | /payment/paystack/escrow/webhook
            |
            | We therefore do NOT process escrow here.
            |
            */

            if (
                $paymentType
                ===
                'escrow'
            ) {

                Logger::write(
                    'paystack_webhook_listener',
                    [
                        'step' =>
                            'ESCROW_RECEIVED_ON_ADVERT_ENDPOINT',

                        'reference' =>
                            $reference,

                        'escrow_id' =>
                            $metadata['escrow_id']
                            ?? null
                    ]
                );

                /*
                 * We acknowledge the webhook but deliberately do not
                 * process escrow here. This prevents duplicate escrow
                 * activation through two different services.
                 */

                http_response_code(200);

                echo 'OK';

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | ADVERT PAYMENT
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'paystack_webhook_listener',
                [
                    'step' =>
                        'ROUTING_ADVERT_WEBHOOK',

                    'reference' =>
                        $reference
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Call Existing Advert Controller
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | We pass the already decoded payload.
            |
            | AdvertPaymentController MUST NOT read php://input again.
            |
            */

            $controller =
                new AdvertPaymentController();


            $controller->webhook(
                $payload
            );


            Logger::write(
                'paystack_webhook_listener',
                [
                    'step' =>
                        'ADVERT_WEBHOOK_COMPLETE',

                    'reference' =>
                        $reference
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Acknowledge
            |--------------------------------------------------------------------------
            */

            http_response_code(200);

            echo 'OK';

        }
        catch (Throwable $e) {

            Logger::write(
                'paystack_webhook_error',
                [
                    'step' =>
                        'LISTENER_EXCEPTION',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),

                    'trace' =>
                        $e->getTraceAsString()
                ]
            );


            http_response_code(500);

            echo 'Webhook failed';
        }
    }
}