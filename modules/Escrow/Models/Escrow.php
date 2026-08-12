<?php

declare(strict_types=1);

namespace Modules\Escrow\Models;

use Core\Database;
use Core\Logger;
use PDO;
use Throwable;

class Escrow
{
    protected string $table = 'escrows';

    /**
     * Valid escrow statuses according to the database ENUM.
     */
    protected array $validStatuses = [
        'pending',
        'paid',
        'item_sent',
        'awaiting_payout',
        'completed',
        'cancelled',
    ];

    /**
     * Statuses which mean payment has already been secured.
     */
    protected array $paidStatuses = [
        'paid',
        'item_sent',
        'awaiting_payout',
        'completed',
    ];

    /*
    |--------------------------------------------------------------------------
    | CREATE ESCROW
    |--------------------------------------------------------------------------
    */

    public function create(array $data): ?array
    {
        try {

            Logger::write(
                'escrow_model',
                [
                    'step' => 'CREATE_START',
                    'data' => $data,
                ]
            );

            $db = Database::getInstance()->connection();

            $amount = (float)($data['amount'] ?? 0);
            $escrowFee = (float)($data['escrow_fee'] ?? 0);
            $paystackFee = (float)($data['paystack_fee'] ?? 0);

            /*
             * If total_amount was not explicitly supplied,
             * calculate it from amount + escrow fee + Paystack fee.
             *
             * This can be overridden by explicitly passing total_amount.
             */
            $totalAmount = array_key_exists('total_amount', $data)
                ? (float)$data['total_amount']
                : ($amount + $escrowFee + $paystackFee);

            $sellerAmount = array_key_exists('seller_amount', $data)
                ? (float)$data['seller_amount']
                : $amount;

            $deliveryType =
                strtolower(
                    trim(
                        (string)($data['delivery_type'] ?? 'digital')
                    )
                );

            if (!in_array($deliveryType, ['digital', 'physical'], true)) {
                $deliveryType = 'digital';
            }

            $status =
                strtolower(
                    trim(
                        (string)($data['status'] ?? 'pending')
                    )
                );

            if (!in_array($status, $this->validStatuses, true)) {
                $status = 'pending';
            }

            $reference =
                strtoupper(
                    trim(
                        (string)($data['reference'] ?? '')
                    )
                );

            if ($reference === '') {
                $reference = $this->generateReference();
            }

            $stmt = $db->prepare(
                "
                INSERT INTO {$this->table}
                (
                    reference,
                    listing_id,
                    buyer_id,
                    seller_id,

                    buyer_phone,
                    seller_phone,

                    buyer_email,
                    seller_email,

                    release_code,

                    amount,
                    escrow_fee,
                    paystack_fee,
                    seller_amount,
                    total_amount,

                    currency,

                    payment_method,

                    delivery_type,

                    payment_reference,

                    payout_reference,

                    payout_paid_at,

                    status,

                    buyer_confirmed_at,
                    seller_confirmed_at,
                    released_at,
                    cancelled_at,

                    expires_at,

                    created_at,
                    updated_at
                )

                VALUES
                (
                    :reference,
                    :listing_id,
                    :buyer_id,
                    :seller_id,

                    :buyer_phone,
                    :seller_phone,

                    :buyer_email,
                    :seller_email,

                    :release_code,

                    :amount,
                    :escrow_fee,
                    :paystack_fee,
                    :seller_amount,
                    :total_amount,

                    :currency,

                    :payment_method,

                    :delivery_type,

                    :payment_reference,

                    :payout_reference,

                    :payout_paid_at,

                    :status,

                    :buyer_confirmed_at,
                    :seller_confirmed_at,
                    :released_at,
                    :cancelled_at,

                    :expires_at,

                    NOW(),
                    NOW()
                )
                "
            );

            $stmt->execute(
                [
                    'reference' =>
                        $reference,

                    'listing_id' =>
                        $data['listing_id'] ?? null,

                    'buyer_id' =>
                        $data['buyer_id'] ?? null,

                    'seller_id' =>
                        $data['seller_id'] ?? null,

                    'buyer_phone' =>
                        $data['buyer_phone'] ?? null,

                    'seller_phone' =>
                        $data['seller_phone'] ?? null,

                    'buyer_email' =>
                        $data['buyer_email'] ?? null,

                    'seller_email' =>
                        $data['seller_email'] ?? null,

                    'release_code' =>
                        $data['release_code'] ?? null,

                    'amount' =>
                        $amount,

                    'escrow_fee' =>
                        $escrowFee,

                    'paystack_fee' =>
                        $paystackFee,

                    'seller_amount' =>
                        $sellerAmount,

                    'total_amount' =>
                        $totalAmount,

                    'currency' =>
                        $data['currency'] ?? 'NGN',

                    'payment_method' =>
                        $data['payment_method'] ?? 'paystack',

                    'delivery_type' =>
                        $deliveryType,

                    'payment_reference' =>
                        $data['payment_reference'] ?? null,

                    'payout_reference' =>
                        $data['payout_reference'] ?? null,

                    'payout_paid_at' =>
                        $data['payout_paid_at'] ?? null,

                    'status' =>
                        $status,

                    'buyer_confirmed_at' =>
                        $data['buyer_confirmed_at'] ?? null,

                    'seller_confirmed_at' =>
                        $data['seller_confirmed_at'] ?? null,

                    'released_at' =>
                        $data['released_at'] ?? null,

                    'cancelled_at' =>
                        $data['cancelled_at'] ?? null,

                    'expires_at' =>
                        $data['expires_at'] ?? null,
                ]
            );

            $id = (int)$db->lastInsertId();

            Logger::write(
                'escrow_model',
                [
                    'step' => 'CREATE_INSERTED',
                    'id' => $id,
                    'reference' => $reference,
                ]
            );

            return $this->find($id);

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'CREATE_FAILED',
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                    'data' => $data,
                ]
            );

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FIND ESCROW BY ID
    |--------------------------------------------------------------------------
    */

    public function find(int $id): ?array
    {
        try {

            Logger::write(
                'escrow_model',
                [
                    'step' => 'FIND_START',
                    'id' => $id,
                ]
            );

            $db = Database::getInstance()->connection();

            $stmt = $db->prepare(
                "
                SELECT *
                FROM {$this->table}
                WHERE id = ?
                LIMIT 1
                "
            );

            $stmt->execute([$id]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            Logger::write(
                'escrow_model',
                [
                    'step' => 'FIND_RESULT',
                    'id' => $id,
                    'found' => (bool)$result,
                    'result' => $result,
                ]
            );

            return $result ?: null;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'FIND_ERROR',
                    'id' => $id,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FIND BY ESCROW REFERENCE
    |--------------------------------------------------------------------------
    */

    public function findByReference(string $reference): ?array
    {
        try {

            $reference =
                strtoupper(
                    trim($reference)
                );

            Logger::write(
                'escrow_model',
                [
                    'step' => 'FIND_REFERENCE_START',
                    'reference' => $reference,
                ]
            );

            if ($reference === '') {
                return null;
            }

            $db = Database::getInstance()->connection();

            $stmt = $db->prepare(
                "
                SELECT *
                FROM {$this->table}
                WHERE reference = ?
                LIMIT 1
                "
            );

            $stmt->execute([$reference]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            Logger::write(
                'escrow_model',
                [
                    'step' => 'FIND_REFERENCE_RESULT',
                    'reference' => $reference,
                    'found' => (bool)$result,
                    'result' => $result,
                ]
            );

            return $result ?: null;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'FIND_REFERENCE_ERROR',
                    'reference' => $reference,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FIND BY PAYMENT REFERENCE
    |--------------------------------------------------------------------------
    */

    public function findByPaymentReference(string $reference): ?array
    {
        try {

            $reference = trim($reference);

            Logger::write(
                'escrow_model',
                [
                    'step' => 'FIND_PAYMENT_REFERENCE_START',
                    'payment_reference' => $reference,
                ]
            );

            if ($reference === '') {
                return null;
            }

            $db = Database::getInstance()->connection();

            $stmt = $db->prepare(
                "
                SELECT *
                FROM {$this->table}
                WHERE payment_reference = ?
                LIMIT 1
                "
            );

            $stmt->execute([$reference]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            Logger::write(
                'escrow_model',
                [
                    'step' => 'FIND_PAYMENT_REFERENCE_RESULT',
                    'payment_reference' => $reference,
                    'found' => (bool)$result,
                    'result' => $result,
                ]
            );

            return $result ?: null;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'FIND_PAYMENT_REFERENCE_ERROR',
                    'payment_reference' => $reference,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FIND EXISTING OPEN ESCROW
    |--------------------------------------------------------------------------
    */

    public function findOpenByBuyerAndListing(
        int $buyerId,
        int $listingId
    ): ?array {
        try {

            Logger::write(
                'escrow_model',
                [
                    'step' => 'FIND_OPEN_START',
                    'buyer_id' => $buyerId,
                    'listing_id' => $listingId,
                ]
            );

            $db = Database::getInstance()->connection();

            $stmt = $db->prepare(
                "
                SELECT *
                FROM {$this->table}

                WHERE buyer_id = :buyer_id
                AND listing_id = :listing_id

                AND status IN
                (
                    'pending',
                    'paid',
                    'item_sent',
                    'awaiting_payout'
                )

                ORDER BY id DESC
                LIMIT 1
                "
            );

            $stmt->execute(
                [
                    'buyer_id' => $buyerId,
                    'listing_id' => $listingId,
                ]
            );

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            Logger::write(
                'escrow_model',
                [
                    'step' => 'FIND_OPEN_RESULT',
                    'buyer_id' => $buyerId,
                    'listing_id' => $listingId,
                    'found' => (bool)$result,
                    'result' => $result,
                ]
            );

            return $result ?: null;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'FIND_OPEN_ERROR',
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE ESCROW
    |--------------------------------------------------------------------------
    */

    public function update(
        int $id,
        array $data
    ): bool {
        try {

            if ($id <= 0 || empty($data)) {

                Logger::write(
                    'escrow_model_error',
                    [
                        'step' => 'UPDATE_INVALID_ARGUMENTS',
                        'id' => $id,
                        'data' => $data,
                    ]
                );

                return false;
            }

            Logger::write(
                'escrow_model',
                [
                    'step' => 'UPDATE_START',
                    'id' => $id,
                    'data' => $data,
                ]
            );

            /*
             * Whitelist database columns.
             *
             * This prevents accidental SQL injection through
             * dynamically supplied field names.
             */
            $allowedFields = [
                'reference',
                'listing_id',
                'buyer_id',
                'seller_id',

                'buyer_phone',
                'seller_phone',

                'buyer_email',
                'seller_email',

                'release_code',

                'amount',
                'escrow_fee',
                'paystack_fee',
                'seller_amount',
                'total_amount',

                'currency',
                'payment_method',
                'delivery_type',

                'payment_reference',
                'payout_reference',
                'payout_paid_at',

                'status',

                'buyer_confirmed_at',
                'seller_confirmed_at',
                'released_at',
                'cancelled_at',

                'expires_at',
            ];

            $fields = [];
            $params = [];

            foreach ($data as $key => $value) {

                if (!in_array($key, $allowedFields, true)) {

                    Logger::write(
                        'escrow_model_error',
                        [
                            'step' => 'UPDATE_FIELD_REJECTED',
                            'id' => $id,
                            'field' => $key,
                        ]
                    );

                    continue;
                }

                if (
                    $key === 'status'
                    &&
                    (
                        $value === null
                        ||
                        trim((string)$value) === ''
                    )
                ) {

                    Logger::write(
                        'escrow_model_error',
                        [
                            'step' => 'INVALID_EMPTY_STATUS',
                            'id' => $id,
                        ]
                    );

                    continue;
                }

                if ($key === 'status') {

                    $normalizedStatus =
                        strtolower(
                            trim(
                                (string)$value
                            )
                        );

                    if (
                        !in_array(
                            $normalizedStatus,
                            $this->validStatuses,
                            true
                        )
                    ) {

                        Logger::write(
                            'escrow_model_error',
                            [
                                'step' => 'INVALID_STATUS',
                                'id' => $id,
                                'status' => $value,
                            ]
                        );

                        return false;
                    }

                    $value = $normalizedStatus;
                }

                $parameter = ':field_' . $key;

                $fields[] =
                    "`{$key}` = {$parameter}";

                $params[$parameter] = $value;
            }

            if (empty($fields)) {
                return false;
            }

            $sql =
                "
                UPDATE {$this->table}
                SET
                    " .
                implode(', ', $fields)
                .
                ",
                    updated_at = NOW()
                WHERE id = :escrow_id
                ";

            $params[':escrow_id'] = $id;

            $db = Database::getInstance()->connection();

            $stmt = $db->prepare($sql);

            $result = $stmt->execute($params);

            Logger::write(
                'escrow_model',
                [
                    'step' => 'UPDATE_RESULT',
                    'id' => $id,
                    'success' => $result,
                    'rows_affected' =>
                        $stmt->rowCount(),
                ]
            );

            return $result;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'UPDATE_ERROR',
                    'id' => $id,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK WHETHER PAYMENT HAS ALREADY BEEN PROCESSED
    |--------------------------------------------------------------------------
    */

    public function isPaymentProcessed(
        int $id
    ): bool {
        try {

            $escrow = $this->find($id);

            if (!$escrow) {
                return false;
            }

            $status =
                strtolower(
                    trim(
                        (string)($escrow['status'] ?? '')
                    )
                );

            $paymentReference =
                trim(
                    (string)(
                        $escrow['payment_reference']
                        ?? ''
                    )
                );

            $processed =
                $paymentReference !== ''
                &&
                in_array(
                    $status,
                    $this->paidStatuses,
                    true
                );

            Logger::write(
                'escrow_model',
                [
                    'step' => 'PAYMENT_PROCESSED_CHECK',
                    'escrow_id' => $id,
                    'status' => $status,
                    'payment_reference' => $paymentReference,
                    'processed' => $processed,
                ]
            );

            return $processed;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'PAYMENT_PROCESSED_CHECK_ERROR',
                    'escrow_id' => $id,
                    'message' => $e->getMessage(),
                ]
            );

            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | MARK PAYMENT RECEIVED
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | This method is intentionally idempotent.
    |
    | Repeated Paystack webhooks must NOT:
    |
    | - reset item_sent to paid
    | - reset awaiting_payout to paid
    | - reset completed to paid
    | - create duplicate payment state
    |
    */

    public function markPaid(
        int $id,
        string $paymentReference
    ): bool {
        try {

            $paymentReference =
                trim(
                    $paymentReference
                );

            Logger::write(
                'escrow_model',
                [
                    'step' => 'MARK_PAID_START',
                    'escrow_id' => $id,
                    'payment_reference' => $paymentReference,
                ]
            );

            if ($id <= 0 || $paymentReference === '') {

                Logger::write(
                    'escrow_model_error',
                    [
                        'step' => 'MARK_PAID_INVALID_ARGUMENTS',
                        'escrow_id' => $id,
                        'payment_reference' => $paymentReference,
                    ]
                );

                return false;
            }

            $escrow = $this->find($id);

            if (!$escrow) {

                Logger::write(
                    'escrow_model_error',
                    [
                        'step' => 'MARK_PAID_ESCROW_NOT_FOUND',
                        'escrow_id' => $id,
                    ]
                );

                return false;
            }

            $currentStatus =
                strtolower(
                    trim(
                        (string)($escrow['status'] ?? '')
                    )
                );

            $existingPaymentReference =
                trim(
                    (string)(
                        $escrow['payment_reference']
                        ?? ''
                    )
                );

            /*
             * Same Paystack transaction has already been applied.
             */
            if (
                $existingPaymentReference !== ''
                &&
                hash_equals(
                    $existingPaymentReference,
                    $paymentReference
                )
                &&
                in_array(
                    $currentStatus,
                    $this->paidStatuses,
                    true
                )
            ) {

                Logger::write(
                    'escrow_model',
                    [
                        'step' => 'MARK_PAID_ALREADY_PROCESSED',
                        'escrow_id' => $id,
                        'status' => $currentStatus,
                        'payment_reference' =>
                            $existingPaymentReference,
                    ]
                );

                return true;
            }

            /*
             * Another payment reference is already attached.
             *
             * Never silently replace it.
             */
            if (
                $existingPaymentReference !== ''
                &&
                !hash_equals(
                    $existingPaymentReference,
                    $paymentReference
                )
            ) {

                Logger::write(
                    'escrow_model_error',
                    [
                        'step' => 'PAYMENT_REFERENCE_CONFLICT',
                        'escrow_id' => $id,
                        'existing_reference' =>
                            $existingPaymentReference,
                        'incoming_reference' =>
                            $paymentReference,
                    ]
                );

                return false;
            }

            /*
             * Payment has already moved beyond paid.
             *
             * Never move it backwards.
             */
            if (
                in_array(
                    $currentStatus,
                    [
                        'item_sent',
                        'awaiting_payout',
                        'completed',
                    ],
                    true
                )
            ) {

                Logger::write(
                    'escrow_model',
                    [
                        'step' => 'MARK_PAID_ADVANCED_STATUS',
                        'escrow_id' => $id,
                        'status' => $currentStatus,
                    ]
                );

                /*
                 * If no payment reference exists, attach the reference
                 * without changing the advanced status.
                 */
                if ($existingPaymentReference === '') {

                    return $this->update(
                        $id,
                        [
                            'payment_reference' =>
                                $paymentReference,
                        ]
                    );
                }

                return true;
            }

            /*
             * Only pending escrow may transition to paid.
             */
            if ($currentStatus !== 'pending') {

                Logger::write(
                    'escrow_model_error',
                    [
                        'step' => 'MARK_PAID_INVALID_STATUS',
                        'escrow_id' => $id,
                        'status' => $currentStatus,
                    ]
                );

                return false;
            }

            /*
             * Atomic transition:
             *
             * pending -> paid
             *
             * The WHERE clause prevents a race condition where two
             * webhook requests try to process the same escrow.
             */
            $db = Database::getInstance()->connection();

            $stmt = $db->prepare(
                "
                UPDATE {$this->table}

                SET
                    payment_reference = :payment_reference,
                    status = 'paid',
                    updated_at = NOW()

                WHERE id = :id
                AND status = 'pending'
                AND
                (
                    payment_reference IS NULL
                    OR payment_reference = ''
                )
                "
            );

            $stmt->execute(
                [
                    'payment_reference' =>
                        $paymentReference,

                    'id' =>
                        $id,
                ]
            );

            $affected =
                $stmt->rowCount();

            Logger::write(
                'escrow_model',
                [
                    'step' => 'MARK_PAID_ATOMIC_RESULT',
                    'escrow_id' => $id,
                    'payment_reference' => $paymentReference,
                    'rows_affected' => $affected,
                ]
            );

            /*
             * Another concurrent request may have completed the update.
             * Reload the row and determine the final state.
             */
            $after = $this->find($id);

            if (
                $after
                &&
                in_array(
                    strtolower(
                        trim(
                            (string)($after['status'] ?? '')
                        )
                    ),
                    $this->paidStatuses,
                    true
                )
                &&
                trim(
                    (string)(
                        $after['payment_reference']
                        ?? ''
                    )
                ) === $paymentReference
            ) {

                Logger::write(
                    'escrow_model',
                    [
                        'step' => 'MARK_PAID_CONFIRMED',
                        'escrow_id' => $id,
                        'after_update' => $after,
                    ]
                );

                return true;
            }

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'MARK_PAID_FAILED',
                    'escrow_id' => $id,
                    'payment_reference' => $paymentReference,
                    'after_update' => $after,
                ]
            );

            return false;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'MARK_PAID_EXCEPTION',
                    'escrow_id' => $id,
                    'payment_reference' => $paymentReference,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | BUYER CONFIRM ITEM RECEIVED
    |--------------------------------------------------------------------------
    */

    public function buyerConfirm(
        int $id
    ): bool {
        try {

            Logger::write(
                'escrow_model',
                [
                    'step' => 'BUYER_CONFIRM_START',
                    'escrow_id' => $id,
                ]
            );

            $escrow = $this->find($id);

            if (!$escrow) {
                return false;
            }

            $status =
                strtolower(
                    trim(
                        (string)($escrow['status'] ?? '')
                    )
                );

            /*
             * Buyer can confirm only after seller has sent the item.
             */
            if ($status !== 'item_sent') {

                Logger::write(
                    'escrow_model_error',
                    [
                        'step' => 'BUYER_CONFIRM_INVALID_STATUS',
                        'escrow_id' => $id,
                        'status' => $status,
                    ]
                );

                return false;
            }

            return $this->update(
                $id,
                [
                    'buyer_confirmed_at' =>
                        date('Y-m-d H:i:s'),

                    'status' =>
                        'awaiting_payout',
                ]
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'BUYER_CONFIRM_ERROR',
                    'escrow_id' => $id,
                    'message' => $e->getMessage(),
                ]
            );

            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SELLER CONFIRM ITEM SENT
    |--------------------------------------------------------------------------
    */

    public function sellerConfirm(
        int $id
    ): bool {
        try {

            Logger::write(
                'escrow_model',
                [
                    'step' => 'SELLER_CONFIRM_START',
                    'escrow_id' => $id,
                ]
            );

            $escrow = $this->find($id);

            if (!$escrow) {
                return false;
            }

            $status =
                strtolower(
                    trim(
                        (string)($escrow['status'] ?? '')
                    )
                );

            /*
             * Seller can ship only after payment has been secured.
             */
            if ($status !== 'paid') {

                Logger::write(
                    'escrow_model_error',
                    [
                        'step' => 'SELLER_CONFIRM_INVALID_STATUS',
                        'escrow_id' => $id,
                        'status' => $status,
                    ]
                );

                return false;
            }

            $result =
                $this->update(
                    $id,
                    [
                        'status' =>
                            'item_sent',

                        'seller_confirmed_at' =>
                            date('Y-m-d H:i:s'),
                    ]
                );

            Logger::write(
                'escrow_model',
                [
                    'step' => 'SELLER_CONFIRM_RESULT',
                    'escrow_id' => $id,
                    'success' => $result,
                ]
            );

            return $result;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'SELLER_CONFIRM_ERROR',
                    'escrow_id' => $id,
                    'message' => $e->getMessage(),
                ]
            );

            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RELEASE ESCROW
    |--------------------------------------------------------------------------
    */

    public function release(
        int $id
    ): bool {
        try {

            Logger::write(
                'escrow_model',
                [
                    'step' => 'RELEASE_START',
                    'escrow_id' => $id,
                ]
            );

            $escrow = $this->find($id);

            if (!$escrow) {
                return false;
            }

            $status =
                strtolower(
                    trim(
                        (string)($escrow['status'] ?? '')
                    )
                );

            /*
             * Money should only be released after buyer confirmation.
             */
            if ($status !== 'awaiting_payout') {

                /*
                 * Already completed is idempotent.
                 */
                if ($status === 'completed') {

                    Logger::write(
                        'escrow_model',
                        [
                            'step' =>
                                'RELEASE_ALREADY_COMPLETED',

                            'escrow_id' =>
                                $id,
                        ]
                    );

                    return true;
                }

                Logger::write(
                    'escrow_model_error',
                    [
                        'step' =>
                            'RELEASE_INVALID_STATUS',

                        'escrow_id' =>
                            $id,

                        'status' =>
                            $status,
                    ]
                );

                return false;
            }

            return $this->update(
                $id,
                [
                    'status' =>
                        'completed',

                    'released_at' =>
                        date('Y-m-d H:i:s'),
                ]
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'RELEASE_ERROR',
                    'escrow_id' => $id,
                    'message' => $e->getMessage(),
                ]
            );

            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CANCEL ESCROW
    |--------------------------------------------------------------------------
    */

    public function cancel(
        int $id
    ): bool {
        try {

            Logger::write(
                'escrow_model',
                [
                    'step' => 'CANCEL_START',
                    'escrow_id' => $id,
                ]
            );

            $escrow = $this->find($id);

            if (!$escrow) {
                return false;
            }

            $status =
                strtolower(
                    trim(
                        (string)($escrow['status'] ?? '')
                    )
                );

            /*
             * Never cancel money that has already been released.
             */
            if ($status === 'completed') {

                Logger::write(
                    'escrow_model_error',
                    [
                        'step' =>
                            'CANCEL_COMPLETED_ESCROW',

                        'escrow_id' =>
                            $id,
                    ]
                );

                return false;
            }

            /*
             * Already cancelled = idempotent success.
             */
            if ($status === 'cancelled') {
                return true;
            }

            return $this->update(
                $id,
                [
                    'status' =>
                        'cancelled',

                    'cancelled_at' =>
                        date('Y-m-d H:i:s'),
                ]
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'CANCEL_ERROR',
                    'escrow_id' => $id,
                    'message' => $e->getMessage(),
                ]
            );

            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GENERATE ESCROW REFERENCE
    |--------------------------------------------------------------------------
    */

    public function generateReference(): string
    {
        do {

            $reference =
                'ESC'
                .
                date('Ymd')
                .
                strtoupper(
                    substr(
                        md5(
                            uniqid(
                                '',
                                true
                            )
                        ),
                        0,
                        8
                    )
                );

        } while (
            $this->findByReference($reference) !== null
        );

        Logger::write(
            'escrow_model',
            [
                'step' => 'GENERATE_REFERENCE',
                'reference' => $reference,
            ]
        );

        return $reference;
    }

    /*
    |--------------------------------------------------------------------------
    | GENERATE RELEASE CODE
    |--------------------------------------------------------------------------
    */

    public function generateReleaseCode(): string
    {
        $code =
            strtoupper(
                substr(
                    md5(
                        uniqid(
                            '',
                            true
                        )
                    ),
                    0,
                    6
                )
            );

        Logger::write(
            'escrow_model',
            [
                'step' => 'GENERATE_RELEASE_CODE',
                'code' => $code,
            ]
        );

        return $code;
    }

    /*
    |--------------------------------------------------------------------------
    | SET PAYOUT REFERENCE
    |--------------------------------------------------------------------------
    */

    public function setPayoutReference(
        int $id,
        string $payoutReference
    ): bool {
        $payoutReference =
            trim(
                $payoutReference
            );

        if (
            $id <= 0
            ||
            $payoutReference === ''
        ) {
            return false;
        }

        Logger::write(
            'escrow_model',
            [
                'step' => 'SET_PAYOUT_REFERENCE',
                'escrow_id' => $id,
                'payout_reference' => $payoutReference,
            ]
        );

        return $this->update(
            $id,
            [
                'payout_reference' =>
                    $payoutReference,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MARK PAYOUT PAID
    |--------------------------------------------------------------------------
    */

    public function markPayoutPaid(
        int $id,
        string $payoutReference
    ): bool {
        try {

            $payoutReference =
                trim(
                    $payoutReference
                );

            if (
                $id <= 0
                ||
                $payoutReference === ''
            ) {
                return false;
            }

            $escrow = $this->find($id);

            if (!$escrow) {
                return false;
            }

            $status =
                strtolower(
                    trim(
                        (string)($escrow['status'] ?? '')
                    )
                );

            /*
             * Payout should occur only after buyer confirmation.
             */
            if (
                $status !== 'awaiting_payout'
                &&
                $status !== 'completed'
            ) {

                Logger::write(
                    'escrow_model_error',
                    [
                        'step' =>
                            'PAYOUT_INVALID_STATUS',

                        'escrow_id' =>
                            $id,

                        'status' =>
                            $status,
                    ]
                );

                return false;
            }

            /*
             * Already paid with same payout reference.
             */
            if (
                !empty($escrow['payout_paid_at'])
                &&
                !empty($escrow['payout_reference'])
                &&
                hash_equals(
                    (string)$escrow['payout_reference'],
                    $payoutReference
                )
            ) {
                return true;
            }

            return $this->update(
                $id,
                [
                    'payout_reference' =>
                        $payoutReference,

                    'payout_paid_at' =>
                        date('Y-m-d H:i:s'),

                    'status' =>
                        'completed',

                    'released_at' =>
                        $escrow['released_at']
                        ?? date('Y-m-d H:i:s'),
                ]
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'MARK_PAYOUT_PAID_ERROR',
                    'escrow_id' => $id,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );

            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ESCROW STATISTICS
    |--------------------------------------------------------------------------
    */

    public function statistics(
        int $userId
    ): array {
        try {

            Logger::write(
                'escrow_model',
                [
                    'step' => 'STATISTICS_START',
                    'user_id' => $userId,
                ]
            );

            $db = Database::getInstance()->connection();

            $stmt = $db->prepare(
                "
                SELECT

                    COUNT(*) AS total,

                    SUM(
                        CASE
                            WHEN status = 'pending'
                            THEN 1
                            ELSE 0
                        END
                    ) AS pending,

                    SUM(
                        CASE
                            WHEN status = 'paid'
                            THEN 1
                            ELSE 0
                        END
                    ) AS paid,

                    SUM(
                        CASE
                            WHEN status = 'item_sent'
                            THEN 1
                            ELSE 0
                        END
                    ) AS item_sent,

                    SUM(
                        CASE
                            WHEN status = 'awaiting_payout'
                            THEN 1
                            ELSE 0
                        END
                    ) AS awaiting_payout,

                    SUM(
                        CASE
                            WHEN status = 'completed'
                            THEN 1
                            ELSE 0
                        END
                    ) AS completed,

                    SUM(
                        CASE
                            WHEN status = 'cancelled'
                            THEN 1
                            ELSE 0
                        END
                    ) AS cancelled,

                    COALESCE(
                        SUM(amount),
                        0
                    ) AS total_amount

                FROM {$this->table}

                WHERE buyer_id = :user_id
                OR seller_id = :user_id
                "
            );

            $stmt->execute(
                [
                    'user_id' => $userId,
                ]
            );

            $result =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

            Logger::write(
                'escrow_model',
                [
                    'step' => 'STATISTICS_RESULT',
                    'user_id' => $userId,
                    'result' => $result,
                ]
            );

            return $result ?: [];

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'STATISTICS_ERROR',
                    'user_id' => $userId,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );

            return [];
        }
    }
}