<?php

declare(strict_types=1);

namespace Listeners\Payments;

use Core\Logger;
use Services\Escrow\PaystackEscrowPaymentService;
use Services\Payments\PaystackGateway;
use Throwable;

/**
 * Thin Paystack webhook adapter for escrow payments.
 *
 * Responsibilities:
 *  1. Authenticate the webhook.
 *  2. Decode the event.
 *  3. Accept charge.success.
 *  4. Verify the transaction with Paystack.
 *  5. Delegate the verified transaction to the escrow payment service.
 *
 * No escrow state transitions or metadata business rules belong here.
 */
class EscrowPaystackWebhookListener
{
    public function handle(): void
    {
        $rawPayload = '';

        try {
            $method = strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? '')));

            Logger::write('paystack_escrow_webhook', [
                'step' => 'REQUEST_RECEIVED',
                'method' => $method,
                'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            ]);

            if ($method !== 'POST') {
                $this->json(['success' => false, 'message' => 'Method not allowed.'], 405);
                return;
            }

            $rawPayload = file_get_contents('php://input');

            if (!is_string($rawPayload) || trim($rawPayload) === '') {
                $this->json(['success' => false, 'message' => 'Empty webhook payload.'], 400);
                return;
            }

            if (!$this->validateSignature($rawPayload)) {
                Logger::write('paystack_escrow_webhook_error', [
                    'step' => 'INVALID_SIGNATURE',
                ]);

                $this->json(['success' => false, 'message' => 'Invalid webhook signature.'], 401);
                return;
            }

            $payload = json_decode($rawPayload, true);

            if (!is_array($payload)) {
                $this->json([
                    'success' => false,
                    'message' => 'Invalid webhook payload.',
                ], 400);
                return;
            }

            $event = strtolower(trim((string)($payload['event'] ?? '')));

            Logger::write('paystack_escrow_webhook', [
                'step' => 'EVENT_RECEIVED',
                'event' => $event,
            ]);

            if ($event !== 'charge.success') {
                $this->json([
                    'success' => true,
                    'ignored' => true,
                    'event' => $event,
                ]);
                return;
            }

            $transaction = $payload['data'] ?? null;

            if (!is_array($transaction)) {
                $this->json([
                    'success' => false,
                    'message' => 'Transaction data missing.',
                ], 400);
                return;
            }

            $reference = strtoupper(trim((string)($transaction['reference'] ?? '')));

            if ($reference === '') {
                $this->json([
                    'success' => false,
                    'message' => 'Transaction reference missing.',
                ], 400);
                return;
            }

            Logger::write('paystack_escrow_webhook', [
                'step' => 'REFERENCE_EXTRACTED',
                'reference' => $reference,
            ]);

            /*
             * Never trust the webhook transaction as the final payment truth.
             * Verify the exact Paystack reference through the API.
             */
            $gateway = new PaystackGateway();
            $verification = $gateway->verify($reference);

            if (!is_array($verification) || !($verification['success'] ?? false)) {
                $retry = (bool)($verification['retry'] ?? true);

                Logger::write('paystack_escrow_webhook_error', [
                    'step' => 'VERIFICATION_FAILED',
                    'reference' => $reference,
                    'retry' => $retry,
                    'message' => $verification['message'] ?? null,
                ]);

                $this->json([
                    'success' => false,
                    'retry' => $retry,
                    'message' => $verification['message'] ?? 'Payment verification failed.',
                    'reference' => $reference,
                ], $retry ? 500 : 400);
                return;
            }

            $verifiedTransaction = $verification['data'] ?? null;

            if (!is_array($verifiedTransaction)) {
                $this->json([
                    'success' => false,
                    'retry' => true,
                    'message' => 'Verified transaction data is unavailable.',
                    'reference' => $reference,
                ], 500);
                return;
            }

            /*
             * PaystackGateway has already verified and normalized these fields.
             * Use the verified response only; do not merge unverified webhook
             * metadata into it.
             */
            $verifiedReference = strtoupper(trim((string)($verification['reference'] ?? $verifiedTransaction['reference'] ?? '')));
            $verifiedMetadata = $verification['metadata'] ?? ($verifiedTransaction['metadata'] ?? []);

            if (!is_array($verifiedMetadata)) {
                $verifiedMetadata = [];
            }

            $verifiedTransaction['reference'] = $verifiedReference;
            $verifiedTransaction['metadata'] = $verifiedMetadata;

            Logger::write('paystack_escrow_webhook', [
                'step' => 'VERIFIED_TRANSACTION_READY',
                'reference' => $verifiedReference,
                'status' => $verification['status'] ?? $verifiedTransaction['status'] ?? null,
                'amount_kobo' => $verification['amount_kobo'] ?? $verifiedTransaction['amount'] ?? null,
                'metadata_type' => $verifiedMetadata['type'] ?? null,
                'escrow_id' => $verifiedMetadata['escrow_id'] ?? null,
                'escrow_reference' => $verifiedMetadata['escrow_reference'] ?? null,
            ]);

            /*
             * The payment service owns all escrow-specific validation:
             * payment type, escrow lookup, reference integrity, amount,
             * currency, idempotency and markPaid().
             */
            $service = new PaystackEscrowPaymentService();
            $result = $service->process($verifiedTransaction);

            if (!is_array($result)) {
                $this->json([
                    'success' => false,
                    'retry' => true,
                    'message' => 'Escrow payment service returned an invalid result.',
                    'reference' => $verifiedReference,
                ], 500);
                return;
            }

            $success = (bool)($result['success'] ?? false);

            if (!$success) {
                $retry = (bool)($result['retry'] ?? true);

                Logger::write('paystack_escrow_webhook_error', [
                    'step' => 'ESCROW_PROCESS_FAILED',
                    'reference' => $verifiedReference,
                    'retry' => $retry,
                    'message' => $result['message'] ?? null,
                    'escrow_id' => $result['escrow_id'] ?? ($verifiedMetadata['escrow_id'] ?? null),
                ]);

                $this->json([
                    'success' => false,
                    'retry' => $retry,
                    'message' => $result['message'] ?? 'Unable to process escrow payment.',
                    'reference' => $verifiedReference,
                    'escrow_id' => $result['escrow_id'] ?? ($verifiedMetadata['escrow_id'] ?? null),
                ], $retry ? 500 : 400);
                return;
            }

            $this->json([
                'success' => true,
                'message' => $result['message'] ?? 'Escrow payment processed successfully.',
                'reference' => $result['reference'] ?? ($verifiedMetadata['escrow_reference'] ?? null),
                'payment_reference' => $verifiedReference,
                'escrow_id' => $result['escrow_id'] ?? ($verifiedMetadata['escrow_id'] ?? null),
                'already_processed' => (bool)($result['already_processed'] ?? false),
            ]);
        } catch (Throwable $e) {
            Logger::write('paystack_escrow_webhook_error', [
                'step' => 'LISTENER_EXCEPTION',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'raw_payload' => $rawPayload,
            ]);

            $this->json([
                'success' => false,
                'retry' => true,
                'message' => 'Escrow webhook processing failed.',
            ], 500);
        }
    }

    protected function validateSignature(string $rawPayload): bool
    {
        try {
            if (!defined('PAYSTACK_SECRET_KEY')) {
                return false;
            }

            $secret = trim((string)PAYSTACK_SECRET_KEY);
            $signature = $this->getHeader('X-Paystack-Signature');

            if ($secret === '' || $signature === '') {
                return false;
            }

            $expected = hash_hmac('sha512', $rawPayload, $secret);

            return hash_equals($expected, trim($signature));
        } catch (Throwable $e) {
            Logger::write('paystack_escrow_webhook_error', [
                'step' => 'SIGNATURE_VALIDATION_EXCEPTION',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return false;
        }
    }

    protected function getHeader(string $name): string
    {
        $name = trim($name);
        if ($name === '') return '';

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $key => $value) {
                    if (strcasecmp(trim((string)$key), $name) === 0) {
                        return trim((string)$value);
                    }
                }
            }
        }

        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($_SERVER[$serverKey])
            ? trim((string)$_SERVER[$serverKey])
            : '';
    }

    protected function json(array $data, int $status = 200): void
    {
        http_response_code($status);

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        echo $json !== false
            ? $json
            : '{"success":false,"message":"Unable to encode response."}';
    }
}
