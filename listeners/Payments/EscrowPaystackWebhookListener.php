<?php

declare(strict_types=1);

namespace Listeners\Payments;

use Core\Logger;
use Services\Payments\PaystackEscrowPaymentService;
use Services\Payments\PaystackGateway;
use Throwable;

class EscrowPaystackWebhookListener
{
    /**
     * ---------------------------------------------------------
     * Handle Paystack Escrow Webhook
     * ---------------------------------------------------------
     *
     * Flow:
     *
     * Paystack
     *     ↓
     * Read raw request
     *     ↓
     * Validate signature
     *     ↓
     * Decode JSON
     *     ↓
     * Accept charge.success only
     *     ↓
     * Extract Paystack reference
     *     ↓
     * Verify transaction directly with Paystack
     *     ↓
     * Validate verified escrow metadata
     *     ↓
     * PaystackEscrowPaymentService::process()
     *     ↓
     * Escrow marked paid
     *     ↓
     * Notifications handled by service
     *
     * ---------------------------------------------------------
     *
     * IMPORTANT
     *
     * This listener does NOT:
     *
     * - initialize payments
     * - mark escrow paid directly
     * - calculate fees
     * - send buyer messages
     * - send seller messages
     * - perform escrow business logic
     *
     * Those responsibilities belong to their respective services.
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

            $method = strtoupper(
                trim(
                    (string)(
                        $_SERVER['REQUEST_METHOD']
                        ?? ''
                    )
                )
            );

            $uri = (string)(
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
            | POST Only
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
            | Raw Payload
            |--------------------------------------------------------------------------
            */

            $rawPayload = file_get_contents(
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
            | Signature
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
                        'step' => 'INVALID_SIGNATURE'
                    ]
                );

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

            $payload = json_decode(
                $rawPayload,
                true
            );

            if (
                !is_array($payload)
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step'       => 'INVALID_JSON',
                        'json_error' => json_last_error_msg()
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
            | Event
            |--------------------------------------------------------------------------
            */

            $event = strtolower(
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
                    'step'  => 'EVENT_RECEIVED',
                    'event' => $event
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Only charge.success
            |--------------------------------------------------------------------------
            |
            | Other Paystack events are irrelevant to this endpoint.
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
            | Transaction
            |--------------------------------------------------------------------------
            */

            $transaction = $payload['data'] ?? null;

            if (
                !is_array($transaction)
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' => 'TRANSACTION_DATA_MISSING'
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
            | Paystack Reference
            |--------------------------------------------------------------------------
            */

            $reference = trim(
                (string)(
                    $transaction['reference']
                    ?? ''
                )
            );

            if ($reference === '') {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' => 'REFERENCE_MISSING'
                    ]
                );

                $this->json(
                    [
                        'success' => false,
                        'message' => 'Transaction reference missing.'
                    ],
                    400
                );

                return;
            }


            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step'      => 'REFERENCE_EXTRACTED',
                    'reference' => $reference,
                    'status'    =>
                        $transaction['status']
                        ?? null
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Verify Directly With Paystack
            |--------------------------------------------------------------------------
            |
            | The signed webhook proves the request came from Paystack.
            |
            | The Paystack API verification proves the transaction itself
            | exists and is successful.
            |
            */

            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step'      => 'VERIFICATION_START',
                    'reference' => $reference
                ]
            );


            $gateway = new PaystackGateway();

            $verification = $gateway->verify(
                $reference
            );


            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step'      => 'VERIFICATION_RESULT',
                    'reference' => $reference,
                    'success'   =>
                        $verification['success']
                        ?? false,
                    'status'    =>
                        $verification['status']
                        ?? null,
                    'retry'     =>
                        $verification['retry']
                        ?? false
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Verification Failure
            |--------------------------------------------------------------------------
            */

            if (
                !($verification['success'] ?? false)
            ) {

                $retry = (bool)(
                    $verification['retry']
                    ?? true
                );

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step'      => 'VERIFICATION_FAILED',
                        'reference' => $reference,
                        'retry'     => $retry,
                        'message'   =>
                            $verification['message']
                            ?? null
                    ]
                );


                /*
                |--------------------------------------------------------------------------
                | Retryable Failure
                |--------------------------------------------------------------------------
                |
                | Return 500 so Paystack can retry.
                |
                */

                if ($retry) {

                    $this->json(
                        [
                            'success' => false,
                            'retry'   => true,
                            'message' =>
                                'Payment verification temporarily failed.',
                            'reference' =>
                                $reference
                        ],
                        500
                    );

                    return;
                }


                /*
                |--------------------------------------------------------------------------
                | Permanent Verification Failure
                |--------------------------------------------------------------------------
                */

                $this->json(
                    [
                        'success' => false,
                        'retry'   => false,
                        'message' =>
                            $verification['message']
                            ??
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
                        'step'      => 'VERIFIED_TRANSACTION_MISSING',
                        'reference' => $reference
                    ]
                );

                $this->json(
                    [
                        'success' => false,
                        'retry'   => true,
                        'message' =>
                            'Verified transaction data is unavailable.',
                        'reference' =>
                            $reference
                    ],
                    500
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Verify Reference
            |--------------------------------------------------------------------------
            */

            $verifiedReference = trim(
                (string)(
                    $verifiedTransaction['reference']
                    ?? ''
                )
            );


            if (
                $verifiedReference === ''
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step'      => 'VERIFIED_REFERENCE_MISSING',
                        'reference' => $reference
                    ]
                );

                $this->json(
                    [
                        'success' => false,
                        'retry'   => false,
                        'message' =>
                            'Verified transaction reference is missing.',
                        'reference' =>
                            $reference
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
                            $verifiedReference
                    ]
                );

                $this->json(
                    [
                        'success' => false,
                        'retry'   => false,
                        'message' =>
                            'Transaction reference mismatch.',
                        'reference' =>
                            $reference
                    ],
                    400
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Verified Status
            |--------------------------------------------------------------------------
            */

            $verifiedStatus = strtolower(
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
                        'step'      => 'VERIFIED_PAYMENT_NOT_SUCCESSFUL',
                        'reference' => $reference,
                        'status'    => $verifiedStatus
                    ]
                );

                $this->json(
                    [
                        'success' => false,
                        'retry'   => false,
                        'message' =>
                            'Verified payment is not successful.',
                        'reference' =>
                            $reference,
                        'status' =>
                            $verifiedStatus
                    ],
                    400
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Metadata
            |--------------------------------------------------------------------------
            |
            | PaystackGateway::verify() already normalizes metadata.
            |
            | We only fall back to the signed webhook metadata if the
            | verified response does not contain it.
            |
            */

            $verifiedMetadata =
                $verifiedTransaction['metadata']
                ?? [];


            if (
                !is_array($verifiedMetadata)
            ) {
                $verifiedMetadata = [];
            }


            $webhookMetadata =
                $transaction['metadata']
                ?? [];


            if (
                !is_array($webhookMetadata)
            ) {
                $webhookMetadata = [];
            }


            /*
            |--------------------------------------------------------------------------
            | Metadata Merge
            |--------------------------------------------------------------------------
            |
            | Verified Paystack data remains authoritative.
            |
            | Webhook metadata is only used to fill missing fields.
            |
            */

            $metadata = array_merge(
                $webhookMetadata,
                $verifiedMetadata
            );


            $verifiedTransaction['metadata'] =
                $metadata;


            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step'      => 'VERIFIED_METADATA_READY',
                    'reference' => $verifiedReference,
                    'metadata'  => $metadata
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Payment Type
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | PaystackEscrowPaymentService currently expects:
            |
            |     type = escrow
            |
            | Therefore initialization must use the same value.
            |
            */

            $paymentType = strtolower(
                trim(
                    (string)(
                        $metadata['type']
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
                        'reference' => $verifiedReference,
                        'type'      => $paymentType
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | Not Our Transaction
                |--------------------------------------------------------------------------
                |
                | This endpoint should acknowledge unrelated Paystack
                | transactions instead of retrying them forever.
                |
                */

                $this->json(
                    [
                        'success' => true,
                        'ignored' => true,
                        'message' =>
                            'Transaction is not an escrow payment.',
                        'reference' =>
                            $verifiedReference
                    ],
                    200
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Escrow ID
            |--------------------------------------------------------------------------
            */

            $escrowId = (int)(
                $metadata['escrow_id']
                ?? 0
            );


            if (
                $escrowId <= 0
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step'      => 'ESCROW_ID_MISSING',
                        'reference' => $verifiedReference,
                        'metadata'  => $metadata
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | This is not a transient Paystack problem.
                |--------------------------------------------------------------------------
                */

                $this->json(
                    [
                        'success' => false,
                        'retry'   => false,
                        'message' =>
                            'Escrow ID is missing from payment metadata.',
                        'reference' =>
                            $verifiedReference
                    ],
                    400
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Escrow Reference
            |--------------------------------------------------------------------------
            */

            $escrowReference = strtoupper(
                trim(
                    (string)(
                        $metadata['escrow_reference']
                        ?? ''
                    )
                )
            );


            if (
                $escrowReference === ''
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step'      => 'ESCROW_REFERENCE_MISSING',
                        'reference' => $verifiedReference,
                        'escrow_id' => $escrowId
                    ]
                );

                $this->json(
                    [
                        'success' => false,
                        'retry'   => false,
                        'message' =>
                            'Escrow reference is missing from payment metadata.',
                        'reference' =>
                            $verifiedReference,
                        'escrow_id' =>
                            $escrowId
                    ],
                    400
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Final Payment Reference Protection
            |--------------------------------------------------------------------------
            */

            $verifiedTransaction['reference'] =
                $verifiedReference;


            /*
            |--------------------------------------------------------------------------
            | Process Escrow
            |--------------------------------------------------------------------------
            |
            | This is the only business-service call made by this listener.
            |
            */

            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step'            => 'ESCROW_PROCESS_START',
                    'payment_reference' =>
                        $verifiedReference,
                    'escrow_reference' =>
                        $escrowReference,
                    'escrow_id' =>
                        $escrowId
                ]
            );


            $service =
                new PaystackEscrowPaymentService();


            $result =
                $service->process(
                    $verifiedTransaction
                );


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

                        'escrow_id' =>
                            $escrowId
                    ]
                );

                $this->json(
                    [
                        'success' => false,
                        'retry'   => true,
                        'message' =>
                            'Escrow payment service returned an invalid result.',
                        'reference' =>
                            $verifiedReference,
                        'escrow_id' =>
                            $escrowId
                    ],
                    500
                );

                return;
            }


            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step'            => 'ESCROW_PROCESS_RESULT',
                    'payment_reference' =>
                        $verifiedReference,
                    'escrow_reference' =>
                        $escrowReference,
                    'escrow_id' =>
                        $escrowId,
                    'success' =>
                        $result['success']
                        ?? false,
                    'already_processed' =>
                        $result['already_processed']
                        ?? false
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Service Failed
            |--------------------------------------------------------------------------
            */

            if (
                !($result['success'] ?? false)
            ) {

                $retry = (bool)(
                    $result['retry']
                    ?? true
                );


                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step'            => 'ESCROW_PROCESS_FAILED',
                        'payment_reference' =>
                            $verifiedReference,
                        'escrow_id' =>
                            $escrowId,
                        'retry' =>
                            $retry,
                        'message' =>
                            $result['message']
                            ?? null
                    ]
                );


                /*
                |--------------------------------------------------------------------------
                | Retryable Internal Failure
                |--------------------------------------------------------------------------
                */

                if ($retry) {

                    $this->json(
                        [
                            'success' => false,
                            'retry'   => true,
                            'message' =>
                                $result['message']
                                ??
                                'Unable to process escrow payment.',
                            'reference' =>
                                $verifiedReference,
                            'escrow_id' =>
                                $escrowId
                        ],
                        500
                    );

                    return;
                }


                /*
                |--------------------------------------------------------------------------
                | Permanent Business Failure
                |--------------------------------------------------------------------------
                */

                $this->json(
                    [
                        'success' => false,
                        'retry'   => false,
                        'message' =>
                            $result['message']
                            ??
                            'Escrow payment could not be processed.',
                        'reference' =>
                            $verifiedReference,
                        'escrow_id' =>
                            $escrowId
                    ],
                    400
                );

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Duplicate Webhook
            |--------------------------------------------------------------------------
            */

            $alreadyProcessed = (bool)(
                $result['already_processed']
                ?? false
            );


            if ($alreadyProcessed) {

                Logger::write(
                    'paystack_escrow_webhook',
                    [
                        'step' =>
                            'ESCROW_ALREADY_PROCESSED',

                        'payment_reference' =>
                            $verifiedReference,

                        'escrow_reference' =>
                            $escrowReference,

                        'escrow_id' =>
                            $escrowId
                    ]
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Success
            |--------------------------------------------------------------------------
            */

            $finalEscrowId = (int)(
                $result['escrow_id']
                ?? $escrowId
            );


            $message =
                trim(
                    (string)(
                        $result['message']
                        ?? ''
                    )
                );


            if ($message === '') {

                $message =
                    $alreadyProcessed
                    ? 'Escrow payment was already processed.'
                    : 'Escrow payment processed successfully.';
            }


            Logger::write(
                'paystack_escrow_webhook',
                [
                    'step' =>
                        'WEBHOOK_COMPLETE',

                    'payment_reference' =>
                        $verifiedReference,

                    'escrow_reference' =>
                        $escrowReference,

                    'escrow_id' =>
                        $finalEscrowId,

                    'already_processed' =>
                        $alreadyProcessed
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | IMPORTANT
            |--------------------------------------------------------------------------
            |
            | Always return 200 after successful processing.
            |
            | This also prevents Paystack from repeatedly sending an already
            | processed webhook.
            |
            */

            $this->json(
                [
                    'success' =>
                        true,

                    'message' =>
                        $message,

                    'reference' =>
                        $verifiedReference,

                    'escrow_reference' =>
                        $escrowReference,

                    'escrow_id' =>
                        $finalEscrowId,

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
            | Internal Exception
            |--------------------------------------------------------------------------
            |
            | Return 500 so the webhook remains retryable.
            |
            */

            $this->json(
                [
                    'success' => false,
                    'retry'   => true,
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
     * Paystack:
     *
     * hash_hmac(
     *     'sha512',
     *     raw_payload,
     *     PAYSTACK_SECRET_KEY
     * )
     *
     * Header:
     *
     * X-Paystack-Signature
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


            if (
                !defined(
                    'PAYSTACK_SECRET_KEY'
                )
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'PAYSTACK_SECRET_KEY_NOT_DEFINED'
                    ]
                );

                return false;
            }


            $secret =
                trim(
                    (string)PAYSTACK_SECRET_KEY
                );


            if (
                $secret === ''
            ) {

                Logger::write(
                    'paystack_escrow_webhook_error',
                    [
                        'step' =>
                            'PAYSTACK_SECRET_KEY_EMPTY'
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
                        strtolower(
                            trim(
                                (string)$key
                            )
                        )
                        ===
                        strtolower(
                            $name
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
        | PHP-FPM / Apache / Nginx
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


        /*
        |--------------------------------------------------------------------------
        | Some servers expose Authorization-style headers differently.
        |--------------------------------------------------------------------------
        */

        $normalizedName =
            strtoupper(
                str_replace(
                    '-',
                    '_',
                    $name
                )
            );


        if (
            isset(
                $_SERVER[$normalizedName]
            )
        ) {

            return trim(
                (string)(
                    $_SERVER[$normalizedName]
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


        if (
            $encoded === false
        ) {

            echo '{"success":false,"message":"Unable to encode response."}';

            return;
        }


        echo $encoded;
    }
}