<?php

declare(strict_types=1);

namespace Listeners\Payments;

use Core\Logger;
use Services\Payments\PaystackGateway;
use Services\Payments\PaystackEscrowPaymentService;
use Throwable;

class EscrowPaystackWebhookListener
{
    /**
     * ---------------------------------------------------------
     * Handle Paystack Escrow Webhook
     * ---------------------------------------------------------
     *
     * Endpoint:
     *
     * POST /payment/paystack/escrow/webhook
     *
     * Flow:
     *
     * Paystack
     *    ↓
     * Webhook signature validation
     *    ↓
     * charge.success validation
     *    ↓
     * Paystack transaction verification
     *    ↓
     * PaystackEscrowPaymentService::process()
     *    ↓
     * Escrow marked paid
     *    ↓
     * Buyer + seller notifications
     *
     * ---------------------------------------------------------
     *
     * IMPORTANT
     *
     * This listener does NOT initialize payments.
     *
     * Payment initialization belongs to:
     *
     * POST /api/escrow/payment
     *
     * This listener ONLY receives the webhook after Paystack
     * reports a payment event.
     *
     * ---------------------------------------------------------
     */
    public function handle(): void
    {
        $rawPayload = '';

        try {

            /*
            |--------------------------------------------------------------------------
            | Request Information
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
                    'step'   => 'REQUEST_RECEIVED',
                    'method' => $method,
                    'uri'    => $uri,
                    'time'   => date('Y-m-d H:i:s')
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Only POST Is Accepted
            |--------------------------------------------------------------------------
            */

            if ($method !== 'POST') {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step'   => 'INVALID_METHOD',
                        'method' => $method,
                        'uri'    => $uri
                    ]
                );

                $this->json(
                    [
                        'success' => false,
                        'message' => 'Method not allowed.'
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
                        'step' => 'EMPTY_PAYLOAD'
                    ]
                );

                $this->json(
                    [
                        'success' => false,
                        'message' => 'Empty webhook payload.'
                    ],
                    400
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Webhook Signature Validation
            |--------------------------------------------------------------------------
            |
            | Paystack signs the raw request body with the secret key.
            |
            | We validate the signature BEFORE trusting the payload.
            |
            */

            if (!$this->validateSignature($rawPayload)) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' => 'INVALID_SIGNATURE',
                        'uri'  => $uri
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | Return 401
                |--------------------------------------------------------------------------
                |
                | Do not process an unauthenticated webhook.
                |
                */

                $this->json(
                    [
                        'success' => false,
                        'message' => 'Invalid webhook signature.'
                    ],
                    401
                );

                return;
            }


            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step' => 'SIGNATURE_VALID'
                ]
            );


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
                            json_last_error_msg()
                    ]
                );

                $this->json(
                    [
                        'success' => false,
                        'message' => 'Invalid webhook payload.'
                    ],
                    400
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Basic Payload Validation
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


            $transaction =
                $payload['data']
                ??
                null;


            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step'  => 'PAYLOAD_DECODED',
                    'event' => $event
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Event Handling
            |--------------------------------------------------------------------------
            |
            | Only charge.success represents a successful payment that
            | this webhook needs to process.
            |
            */

            if ($event !== 'charge.success') {

                Logger::write(
                    'paystack_escrow_webhook',
                    [
                        'step'  => 'EVENT_IGNORED',
                        'event' => $event
                    ]
                );


                /*
                |--------------------------------------------------------------------------
                | Paystack Retry Protection
                |--------------------------------------------------------------------------
                |
                | Intentionally ignored events should still receive 200.
                |
                */

                $this->json(
                    [
                        'success' => true,
                        'ignored' => true,
                        'event'   => $event
                    ],
                    200
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Transaction Data
            |--------------------------------------------------------------------------
            */

            if (
                !is_array($transaction)
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step'  => 'TRANSACTION_DATA_MISSING',
                        'event' => $event
                    ]
                );

                $this->json(
                    [
                        'success' => false,
                        'message' => 'Transaction data missing.'
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
                        $transaction['reference']
                        ?? ''
                    )
                );


            if ($reference === '') {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' => 'REFERENCE_MISSING',
                        'event' => $event
                    ]
                );

                $this->json(
                    [
                        'success' => false,
                        'message' =>
                            'Transaction reference missing.'
                    ],
                    400
                );

                return;
            }


            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step'      => 'TRANSACTION_EXTRACTED',
                    'reference' => $reference,
                    'status'    =>
                        $transaction['status']
                        ?? null
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Read Webhook Metadata
            |--------------------------------------------------------------------------
            |
            | Keep this available because the verified Paystack transaction
            | may not always contain metadata in exactly the same structure.
            |
            */

            $webhookMetadata =
                $transaction['metadata']
                ??
                [];


            if (
                !is_array($webhookMetadata)
            ) {

                $webhookMetadata = [];
            }


            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step'     => 'WEBHOOK_METADATA_LOADED',
                    'reference' => $reference,
                    'metadata' => $webhookMetadata
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Confirm This Is Actually An Escrow Payment
            |--------------------------------------------------------------------------
            |
            | This prevents an advert payment accidentally entering the
            | escrow workflow.
            |
            */

            $paymentType =
                strtolower(
                    trim(
                        (string)(
                            $webhookMetadata['type']
                            ?? ''
                        )
                    )
                );


            if (
                $paymentType !== 'escrow'
            ) {

                Logger::write(
                    'paystack_escrow_webhook',
                    [
                        'step'      => 'NOT_ESCROW_PAYMENT',
                        'reference' => $reference,
                        'type'      => $paymentType
                    ]
                );


                /*
                |--------------------------------------------------------------------------
                | Important
                |--------------------------------------------------------------------------
                |
                | This endpoint is dedicated to escrow.
                |
                | If another Paystack transaction reaches this endpoint,
                | do not process it as escrow.
                |
                */

                $this->json(
                    [
                        'success' => true,
                        'ignored' => true,
                        'message' =>
                            'Transaction is not an escrow payment.',
                        'reference' =>
                            $reference
                    ],
                    200
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Extract Escrow Metadata
            |--------------------------------------------------------------------------
            */

            $escrowId =
                (int)(
                    $webhookMetadata['escrow_id']
                    ??
                    0
                );


            $escrowReference =
                trim(
                    (string)(
                        $webhookMetadata['escrow_reference']
                        ??
                        ''
                    )
                );


            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step'            => 'ESCROW_METADATA_VALIDATED',
                    'reference'       => $reference,
                    'escrow_id'       => $escrowId,
                    'escrow_reference' =>
                        $escrowReference
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Verify Paystack Transaction
            |--------------------------------------------------------------------------
            |
            | Never trust only the webhook body.
            |
            | PaystackGateway::verify() calls:
            |
            | /transaction/verify/{reference}
            |
            */

            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step'      =>
                        'PAYSTACK_VERIFICATION_START',
                    'reference' =>
                        $reference
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
                        'PAYSTACK_VERIFICATION_RESULT',
                    'reference' =>
                        $reference,
                    'success' =>
                        $verification['success']
                        ?? false
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Verification Failed
            |--------------------------------------------------------------------------
            */

            if (
                !($verification['success'] ?? false)
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step'      =>
                            'PAYMENT_VERIFICATION_FAILED',
                        'reference' =>
                            $reference,
                        'message' =>
                            $verification['message']
                            ?? null
                    ]
                );


                /*
                |--------------------------------------------------------------------------
                | IMPORTANT
                |--------------------------------------------------------------------------
                |
                | Do NOT mark escrow paid.
                |
                */

                $this->json(
                    [
                        'success' => false,
                        'message' =>
                            'Payment verification failed.',
                        'reference' =>
                            $reference
                    ],
                    400
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Get Verified Transaction
            |--------------------------------------------------------------------------
            */

            $verifiedTransaction =
                $verification['data']
                ??
                null;


            if (
                !is_array($verifiedTransaction)
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'VERIFIED_TRANSACTION_MISSING',
                        'reference' =>
                            $reference
                    ]
                );


                $this->json(
                    [
                        'success' => false,
                        'message' =>
                            'Verified transaction data missing.',
                        'reference' =>
                            $reference
                    ],
                    400
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Make Paystack Reference Authoritative
            |--------------------------------------------------------------------------
            */

            $verifiedTransaction['reference'] =
                trim(
                    (string)(
                        $verifiedTransaction['reference']
                        ??
                        $reference
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | Make Sure Verified Status Is Successful
            |--------------------------------------------------------------------------
            |
            | PaystackGateway already checks this, but we enforce it here
            | as a second protection layer.
            |
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
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'VERIFIED_STATUS_NOT_SUCCESS',
                        'reference' =>
                            $reference,
                        'status' =>
                            $verifiedStatus
                    ]
                );


                $this->json(
                    [
                        'success' => false,
                        'message' =>
                            'Verified payment is not successful.',
                        'reference' =>
                            $reference
                    ],
                    400
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Preserve Escrow Metadata
            |--------------------------------------------------------------------------
            |
            | Paystack verification is authoritative for transaction status.
            |
            | However, if metadata is missing from the verification response,
            | preserve the metadata from the signed webhook payload.
            |
            */

            $verifiedMetadata =
                $verifiedTransaction['metadata']
                ??
                [];


            if (
                !is_array($verifiedMetadata)
            ) {

                $verifiedMetadata = [];
            }


            $verifiedMetadata =
                array_merge(
                    $webhookMetadata,
                    $verifiedMetadata
                );


            $verifiedTransaction['metadata'] =
                $verifiedMetadata;


            /*
            |--------------------------------------------------------------------------
            | Final Escrow Type Check
            |--------------------------------------------------------------------------
            */

            $verifiedPaymentType =
                strtolower(
                    trim(
                        (string)(
                            $verifiedMetadata['type']
                            ?? ''
                        )
                    )
                );


            if (
                $verifiedPaymentType !== 'escrow'
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'VERIFIED_TRANSACTION_NOT_ESCROW',
                        'reference' =>
                            $reference,
                        'type' =>
                            $verifiedPaymentType
                    ]
                );


                $this->json(
                    [
                        'success' => true,
                        'ignored' => true,
                        'message' =>
                            'Verified transaction is not an escrow payment.',
                        'reference' =>
                            $reference
                    ],
                    200
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Final Escrow ID Check
            |--------------------------------------------------------------------------
            */

            $verifiedEscrowId =
                (int)(
                    $verifiedMetadata['escrow_id']
                    ??
                    0
                );


            if (
                $verifiedEscrowId <= 0
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'VERIFIED_ESCROW_ID_MISSING',
                        'reference' =>
                            $reference,
                        'metadata' =>
                            $verifiedMetadata
                    ]
                );


                $this->json(
                    [
                        'success' => false,
                        'message' =>
                            'Escrow ID missing from verified payment metadata.',
                        'reference' =>
                            $reference
                    ],
                    400
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Process Escrow Payment
            |--------------------------------------------------------------------------
            |
            | THIS IS THE ONLY PLACE WHERE THE ESCROW PAYMENT WORKFLOW
            | IS TRIGGERED FROM THIS WEBHOOK.
            |
            */

            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step' =>
                        'ESCROW_PAYMENT_SERVICE_START',
                    'reference' =>
                        $reference,
                    'escrow_id' =>
                        $verifiedEscrowId
                ]
            );


            $service =
                new PaystackEscrowPaymentService();


            $result =
                $service->process(
                    $verifiedTransaction
                );


            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step' =>
                        'ESCROW_PAYMENT_SERVICE_RESULT',
                    'reference' =>
                        $reference,
                    'escrow_id' =>
                        $verifiedEscrowId,
                    'result' =>
                        $result
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Service Failure
            |--------------------------------------------------------------------------
            */

            if (
                !($result['success'] ?? false)
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'ESCROW_PAYMENT_FAILED',
                        'reference' =>
                            $reference,
                        'escrow_id' =>
                            $verifiedEscrowId,
                        'result' =>
                            $result
                    ]
                );


                /*
                |--------------------------------------------------------------------------
                | Return 500
                |--------------------------------------------------------------------------
                |
                | A temporary/internal processing failure should allow
                | Paystack to retry the webhook.
                |
                */

                $this->json(
                    [
                        'success' => false,
                        'message' =>
                            $result['message']
                            ??
                            'Unable to process escrow payment.',
                        'reference' =>
                            $reference,
                        'escrow_id' =>
                            $verifiedEscrowId
                    ],
                    500
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Already Processed
            |--------------------------------------------------------------------------
            |
            | Paystack can retry webhooks.
            |
            | PaystackEscrowPaymentService::process() detects an already-paid
            | escrow and returns:
            |
            | already_processed = true
            |
            | This is a SUCCESSFUL webhook outcome.
            |
            */

            $alreadyProcessed =
                (bool)(
                    $result['already_processed']
                    ?? false
                );


            if ($alreadyProcessed) {

                Logger::write(
                    'paystack_escrow_webhook',
                    [
                        'step' =>
                            'ALREADY_PROCESSED',
                        'reference' =>
                            $reference,
                        'escrow_id' =>
                            $verifiedEscrowId
                    ]
                );

            }


            /*
            |--------------------------------------------------------------------------
            | Complete
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step' =>
                        'COMPLETE',
                    'reference' =>
                        $reference,
                    'escrow_id' =>
                        $verifiedEscrowId,
                    'already_processed' =>
                        $alreadyProcessed
                ]
            );


            $this->json(
                [
                    'success' =>
                        true,

                    'message' =>
                        $result['message']
                        ??
                        'Escrow payment processed.',

                    'reference' =>
                        $reference,

                    'escrow_id' =>
                        $result['escrow_id']
                        ??
                        $verifiedEscrowId,

                    'already_processed' =>
                        $alreadyProcessed
                ],
                200
            );

        }
        catch (Throwable $e) {

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
                        $rawPayload
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Return 500
            |--------------------------------------------------------------------------
            |
            | Internal exceptions should remain retryable.
            |
            */

            $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Escrow webhook processing failed.'
                ],
                500
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * Validate Paystack Webhook Signature
     * ---------------------------------------------------------
     *
     * Paystack generates:
     *
     * hash_hmac('sha512', raw_payload, secret_key)
     *
     * and sends the result through:
     *
     * X-Paystack-Signature
     *
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
                            'SIGNATURE_HEADER_MISSING'
                    ]
                );

                return false;
            }


            /*
            |--------------------------------------------------------------------------
            | Make Sure Secret Exists
            |--------------------------------------------------------------------------
            */

            if (
                !defined('PAYSTACK_SECRET_KEY')
                ||
                trim(
                    (string)PAYSTACK_SECRET_KEY
                ) === ''
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'PAYSTACK_SECRET_KEY_MISSING'
                    ]
                );

                return false;
            }


            $expected =
                hash_hmac(
                    'sha512',
                    $rawPayload,
                    PAYSTACK_SECRET_KEY
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
                        $valid
                ]
            );


            return $valid;

        }
        catch (Throwable $e) {

            Logger::write(
                'paystack_escrow_webhook_error',
                [
                    'step' =>
                        'SIGNATURE_VALIDATION_EXCEPTION',
                    'message' =>
                        $e->getMessage(),
                    'file' =>
                        $e->getFile(),
                    'line' =>
                        $e->getLine()
                ]
            );

            return false;
        }
    }


    /**
     * ---------------------------------------------------------
     * Get HTTP Header
     * ---------------------------------------------------------
     *
     * Supports:
     *
     * - getallheaders()
     * - $_SERVER HTTP_* headers
     *
     * ---------------------------------------------------------
     */
    protected function getHeader(
        string $name
    ): string {

        /*
        |--------------------------------------------------------------------------
        | getallheaders()
        |--------------------------------------------------------------------------
        */

        if (
            function_exists('getallheaders')
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
                        strtolower(
                            trim(
                                (string)$key
                            )
                        )
                        ===
                        strtolower(
                            trim($name)
                        )
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
        | Apache / Nginx / PHP-FPM Header
        |--------------------------------------------------------------------------
        */

        $serverKey =
            'HTTP_'
            .
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
}
