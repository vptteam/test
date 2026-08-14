<?php

declare(strict_types=1);

namespace Listeners\Payments;

use Core\Logger;
use Services\Escrow\PaystackEscrowPaymentService;
use Services\Payments\PaystackGateway;
use Throwable;

class EscrowPaystackWebhookListener
{
    /**
     * ---------------------------------------------------------
     * Handle Paystack Escrow Webhook
     * ---------------------------------------------------------
     *
     * Canonical flow:
     *
     * Paystack
     *      ↓
     * POST
     *      ↓
     * Signature validation
     *      ↓
     * Decode event
     *      ↓
     * charge.success
     *      ↓
     * Extract Paystack reference
     *      ↓
     * PaystackGateway::verify()
     *      ↓
     * PaystackEscrowPaymentService::process()
     *      ↓
     * Escrow model
     *
     * This listener does NOT:
     *
     * - initialize payment
     * - calculate fees
     * - mark escrow paid
     * - send notifications
     * - modify escrow directly
     *
     * Those responsibilities belong to the services/models.
     *
     * ---------------------------------------------------------
     */
    public function handle(): void
    {
        $rawPayload = '';

        try {

            /*
            |--------------------------------------------------------------------------
            | Request
            |--------------------------------------------------------------------------
            */

            $method =
                strtoupper(
                    trim(
                        (string)(
                            $_SERVER['REQUEST_METHOD']
                            ?? ''
                        )
                    )
                );

            $uri =
                (string)(
                    $_SERVER['REQUEST_URI']
                    ?? ''
                );

            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step' =>
                        'REQUEST_RECEIVED',

                    'method' =>
                        $method,

                    'uri' =>
                        $uri,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | POST Only
            |--------------------------------------------------------------------------
            */

            if (
                $method !== 'POST'
            ) {

                $this->json(
                    [
                        'success' =>
                            false,

                        'message' =>
                            'Method not allowed.',
                    ],
                    405
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Read Raw Payload
            |--------------------------------------------------------------------------
            */

            $rawPayload =
                file_get_contents(
                    'php://input'
                );

            if (
                !is_string($rawPayload)
                ||
                trim($rawPayload) === ''
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'EMPTY_PAYLOAD',
                    ]
                );

                $this->json(
                    [
                        'success' =>
                            false,

                        'message' =>
                            'Empty webhook payload.',
                    ],
                    400
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Signature
            |--------------------------------------------------------------------------
            */

            if (
                !$this->validateSignature(
                    $rawPayload
                )
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'INVALID_SIGNATURE',
                    ]
                );

                $this->json(
                    [
                        'success' =>
                            false,

                        'message' =>
                            'Invalid webhook signature.',
                    ],
                    401
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Decode JSON
            |--------------------------------------------------------------------------
            */

            $payload =
                json_decode(
                    $rawPayload,
                    true
                );

            if (
                !is_array($payload)
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'INVALID_JSON',

                        'json_error' =>
                            json_last_error_msg(),
                    ]
                );

                $this->json(
                    [
                        'success' =>
                            false,

                        'message' =>
                            'Invalid webhook payload.',
                    ],
                    400
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Event
            |--------------------------------------------------------------------------
            */

            $event =
                strtolower(
                    trim(
                        (string)(
                            $payload['event']
                            ?? ''
                        )
                    )
                );

            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step' =>
                        'EVENT_RECEIVED',

                    'event' =>
                        $event,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Ignore Non-Escrow-Relevant Events
            |--------------------------------------------------------------------------
            |
            | Only charge.success is relevant to payment acceptance.
            |
            */

            if (
                $event !== 'charge.success'
            ) {

                $this->json(
                    [
                        'success' =>
                            true,

                        'ignored' =>
                            true,

                        'event' =>
                            $event,
                    ],
                    200
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Webhook Transaction
            |--------------------------------------------------------------------------
            */

            $webhookTransaction =
                $payload['data']
                ?? null;

            if (
                !is_array($webhookTransaction)
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'TRANSACTION_DATA_MISSING',
                    ]
                );

                $this->json(
                    [
                        'success' =>
                            false,

                        'message' =>
                            'Transaction data missing.',
                    ],
                    400
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Extract Paystack Reference
            |--------------------------------------------------------------------------
            */

            $reference =
                trim(
                    (string)(
                        $webhookTransaction['reference']
                        ?? ''
                    )
                );

            if (
                $reference === ''
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'REFERENCE_MISSING',
                    ]
                );

                $this->json(
                    [
                        'success' =>
                            false,

                        'message' =>
                            'Transaction reference missing.',
                    ],
                    400
                );

                return;
            }

            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step' =>
                        'REFERENCE_EXTRACTED',

                    'reference' =>
                        $reference,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Verify Directly With Paystack
            |--------------------------------------------------------------------------
            |
            | The signed webhook authenticates the request.
            |
            | PaystackGateway::verify() gives us the authoritative
            | transaction record.
            |
            */

            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step' =>
                        'PAYSTACK_VERIFY_START',

                    'reference' =>
                        $reference,
                ]
            );

            $gateway =
                new PaystackGateway();

            $verification =
                $gateway->verify(
                    $reference
                );

            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step' =>
                        'PAYSTACK_VERIFY_RESULT',

                    'reference' =>
                        $reference,

                    'success' =>
                        $verification['success']
                        ?? false,

                    'status' =>
                        $verification['status']
                        ?? null,

                    'retry' =>
                        $verification['retry']
                        ?? false,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Verification Failure
            |--------------------------------------------------------------------------
            */

            if (
                !is_array($verification)
                ||
                !($verification['success'] ?? false)
            ) {

                $retry =
                    (bool)(
                        $verification['retry']
                        ?? true
                    );

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'PAYSTACK_VERIFY_FAILED',

                        'reference' =>
                            $reference,

                        'retry' =>
                            $retry,

                        'message' =>
                            $verification['message']
                            ?? null,
                    ]
                );

                $this->json(
                    [
                        'success' =>
                            false,

                        'retry' =>
                            $retry,

                        'message' =>
                            $retry
                            ?
                            'Payment verification temporarily failed.'
                            :
                            (
                                $verification['message']
                                ??
                                'Payment verification failed.'
                            ),

                        'reference' =>
                            $reference,
                    ],
                    $retry
                    ? 500
                    : 400
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Verified Transaction
            |--------------------------------------------------------------------------
            */

            $verifiedTransaction =
                $verification['data']
                ?? null;

            if (
                !is_array($verifiedTransaction)
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'VERIFIED_TRANSACTION_MISSING',

                        'reference' =>
                            $reference,
                    ]
                );

                $this->json(
                    [
                        'success' =>
                            false,

                        'retry' =>
                            true,

                        'message' =>
                            'Verified transaction data is unavailable.',

                        'reference' =>
                            $reference,
                    ],
                    500
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Verify Reference Integrity
            |--------------------------------------------------------------------------
            */

            $verifiedReference =
                trim(
                    (string)(
                        $verifiedTransaction['reference']
                        ?? ''
                    )
                );

            if (
                $verifiedReference === ''
            ) {

                $this->json(
                    [
                        'success' =>
                            false,

                        'retry' =>
                            false,

                        'message' =>
                            'Verified transaction reference is missing.',

                        'reference' =>
                            $reference,
                    ],
                    400
                );

                return;
            }

            if (
                !hash_equals(
                    strtoupper($reference),
                    strtoupper($verifiedReference)
                )
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'VERIFIED_REFERENCE_MISMATCH',

                        'webhook_reference' =>
                            $reference,

                        'verified_reference' =>
                            $verifiedReference,
                    ]
                );

                $this->json(
                    [
                        'success' =>
                            false,

                        'retry' =>
                            false,

                        'message' =>
                            'Transaction reference mismatch.',

                        'reference' =>
                            $reference,
                    ],
                    400
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Verify Payment Status
            |--------------------------------------------------------------------------
            */

            $verifiedStatus =
                strtolower(
                    trim(
                        (string)(
                            $verifiedTransaction['status']
                            ?? ''
                        )
                    )
                );

            if (
                $verifiedStatus !== 'success'
            ) {

                Logger::write(
                    'paystack_escrow_webhook',
                    [
                        'step' =>
                            'VERIFIED_PAYMENT_NOT_SUCCESSFUL',

                        'reference' =>
                            $verifiedReference,

                        'status' =>
                            $verifiedStatus,
                    ]
                );

                /*
                 * This is not an infrastructure failure.
                 * Do not retry indefinitely.
                 */

                $this->json(
                    [
                        'success' =>
                            true,

                        'ignored' =>
                            true,

                        'message' =>
                            'Verified payment is not successful.',

                        'reference' =>
                            $verifiedReference,

                        'status' =>
                            $verifiedStatus,
                    ],
                    200
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Process Escrow Payment
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | We deliberately do NOT merge webhook metadata into
            | the verified transaction.
            |
            | PaystackGateway::verify() is authoritative.
            |
            | PaystackEscrowPaymentService owns:
            |
            | - escrow metadata validation
            | - escrow lookup
            | - payment reference validation
            | - amount validation
            | - currency validation
            | - duplicate protection
            | - markPaid()
            | - notifications
            |
            */

            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step' =>
                        'ESCROW_PROCESS_START',

                    'payment_reference' =>
                        $verifiedReference,

                    'amount_kobo' =>
                        $verifiedTransaction['amount']
                        ?? null,

                    'currency' =>
                        $verifiedTransaction['currency']
                        ?? null,
                ]
            );

            $service =
                new PaystackEscrowPaymentService();

            $result =
                $service->process(
                    $verifiedTransaction
                );

            /*
            |--------------------------------------------------------------------------
            | Validate Service Result
            |--------------------------------------------------------------------------
            */

            if (
                !is_array($result)
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'ESCROW_SERVICE_INVALID_RESULT',

                        'payment_reference' =>
                            $verifiedReference,
                    ]
                );

                $this->json(
                    [
                        'success' =>
                            false,

                        'retry' =>
                            true,

                        'message' =>
                            'Escrow payment service returned an invalid result.',

                        'reference' =>
                            $verifiedReference,
                    ],
                    500
                );

                return;
            }

            $success =
                (bool)(
                    $result['success']
                    ?? false
                );

            $retry =
                (bool)(
                    $result['retry']
                    ?? false
                );

            $alreadyProcessed =
                (bool)(
                    $result['already_processed']
                    ?? false
                );

            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step' =>
                        'ESCROW_PROCESS_RESULT',

                    'payment_reference' =>
                        $verifiedReference,

                    'success' =>
                        $success,

                    'retry' =>
                        $retry,

                    'already_processed' =>
                        $alreadyProcessed,

                    'escrow_reference' =>
                        $result['reference']
                        ?? null,

                    'escrow_id' =>
                        $result['escrow_id']
                        ?? null,

                    'status' =>
                        $result['status']
                        ?? null,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Escrow Processing Failed
            |--------------------------------------------------------------------------
            */

            if (!$success) {

                $this->json(
                    [
                        'success' =>
                            false,

                        'retry' =>
                            $retry,

                        'message' =>
                            $result['message']
                            ??
                            'Escrow payment processing failed.',

                        'reference' =>
                            $verifiedReference,

                        'escrow_reference' =>
                            $result['reference']
                            ?? null,

                        'escrow_id' =>
                            $result['escrow_id']
                            ?? null,
                    ],
                    $retry
                    ? 500
                    : 400
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Success / Idempotent Success
            |--------------------------------------------------------------------------
            |
            | Always return HTTP 200.
            |
            | This prevents Paystack from repeatedly retrying a
            | payment that has already been processed.
            |
            */

            $message =
                $result['message']
                ??
                (
                    $alreadyProcessed
                    ?
                    'Escrow payment was already processed.'
                    :
                    'Escrow payment processed successfully.'
                );

            $this->json(
                [
                    'success' =>
                        true,

                    'message' =>
                        $message,

                    'reference' =>
                        $verifiedReference,

                    'escrow_reference' =>
                        $result['reference']
                        ?? null,

                    'escrow_id' =>
                        $result['escrow_id']
                        ?? null,

                    'status' =>
                        $result['status']
                        ?? null,

                    'already_processed' =>
                        $alreadyProcessed,
                ],
                200
            );

        } catch (Throwable $e) {

            Logger::write(
                'paystack_escrow_webhook_error',
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
                        $e->getTraceAsString(),

                    'raw_payload' =>
                        $rawPayload,
                ]
            );

            /*
             * 500 intentionally causes Paystack to retry.
             */

            $this->json(
                [
                    'success' =>
                        false,

                    'retry' =>
                        true,

                    'message' =>
                        'Escrow webhook processing failed.',
                ],
                500
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * Validate Paystack Signature
     * ---------------------------------------------------------
     */
    protected function validateSignature(
        string $rawPayload
    ): bool {

        try {

            $signature =
                $this->getHeader(
                    'X-Paystack-Signature'
                );

            if (
                $signature === ''
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'SIGNATURE_HEADER_MISSING',
                    ]
                );

                return false;
            }

            if (
                !defined(
                    'PAYSTACK_SECRET_KEY'
                )
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'SECRET_KEY_NOT_DEFINED',
                    ]
                );

                return false;
            }

            $secret =
                trim(
                    (string)(
                        PAYSTACK_SECRET_KEY
                    )
                );

            if (
                $secret === ''
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'SECRET_KEY_EMPTY',
                    ]
                );

                return false;
            }

            $expected =
                hash_hmac(
                    'sha512',
                    $rawPayload,
                    $secret
                );

            $valid =
                hash_equals(
                    $expected,
                    trim($signature)
                );

            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step' =>
                        'SIGNATURE_CHECK',

                    'valid' =>
                        $valid,
                ]
            );

            return $valid;

        } catch (Throwable $e) {

            Logger::write(
                'paystack_escrow_webhook_error',
                [
                    'step' =>
                        'SIGNATURE_EXCEPTION',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );

            return false;
        }
    }


    /**
     * ---------------------------------------------------------
     * Get HTTP Header
     * ---------------------------------------------------------
     */
    protected function getHeader(
        string $name
    ): string {

        $name =
            trim($name);

        if (
            $name === ''
        ) {
            return '';
        }

        /*
        |--------------------------------------------------------------------------
        | getallheaders()
        |--------------------------------------------------------------------------
        */

        if (
            function_exists(
                'getallheaders'
            )
        ) {

            $headers =
                getallheaders();

            if (
                is_array($headers)
            ) {

                foreach (
                    $headers
                    as $key => $value
                ) {

                    if (
                        strcasecmp(
                            trim(
                                (string)$key
                            ),
                            $name
                        ) === 0
                    ) {

                        return trim(
                            (string)$value
                        );
                    }
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | PHP-FPM / Apache / Nginx
        |--------------------------------------------------------------------------
        */

        $serverKey =
            'HTTP_' .
            strtoupper(
                str_replace(
                    '-',
                    '_',
                    $name
                )
            );

        if (
            isset(
                $_SERVER[$serverKey]
            )
        ) {

            return trim(
                (string)(
                    $_SERVER[$serverKey]
                )
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Normalized Header Fallback
        |--------------------------------------------------------------------------
        */

        $normalized =
            strtoupper(
                str_replace(
                    '-',
                    '_',
                    $name
                )
            );

        if (
            isset(
                $_SERVER[$normalized]
            )
        ) {

            return trim(
                (string)(
                    $_SERVER[$normalized]
                )
            );
        }

        return '';
    }


    /**
     * ---------------------------------------------------------
     * JSON Response
     * ---------------------------------------------------------
     */
    protected function json(
        array $data,
        int $status = 200
    ): void {

        http_response_code(
            $status
        );

        if (
            !headers_sent()
        ) {

            header(
                'Content-Type: application/json; charset=utf-8'
            );
        }

        $encoded =
            json_encode(
                $data,
                JSON_UNESCAPED_UNICODE
                |
                JSON_UNESCAPED_SLASHES
            );

        echo
            $encoded !== false
            ?
            $encoded
            :
            '{"success":false,"message":"Unable to encode response."}';
    }
}