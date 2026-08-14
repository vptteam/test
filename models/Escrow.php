<?php

declare(strict_types=1);

namespace Models;

use Core\Database;
use Core\Logger;
use PDO;
use Throwable;

class Escrow
{
    protected string $table = 'escrows';

    protected PDO $db;

    /**
     * Statuses that mean payment has already been accepted.
     */
    protected array $paidStatuses = [
        'paid',
        'item_sent',
        'awaiting_payout',
        'buyer_confirmed',
        'completed',
    ];

    /**
     * Statuses that must never be moved backwards by markPaid().
     */
    protected array $advancedStatuses = [
        'item_sent',
        'awaiting_payout',
        'buyer_confirmed',
        'completed',
    ];

    public function __construct()
    {
        $this->db =
            Database::getInstance()->connection();

        Logger::write(
            'escrow_model',
            [
                'step' => 'CONSTRUCTOR',
                'table' => $this->table,
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * Create Escrow
     * ---------------------------------------------------------
     */
    public function create(array $data): int|false
    {
        try {

            Logger::write(
                'escrow_model',
                [
                    'step' => 'CREATE_START',
                    'data' => $data,
                ]
            );

            $reference =
                strtoupper(
                    trim(
                        (string)($data['reference'] ?? '')
                    )
                );

            $listingId =
                (int)($data['listing_id'] ?? 0);

            $buyerId =
                (int)($data['buyer_id'] ?? 0);

            $sellerId =
                (int)($data['seller_id'] ?? 0);

            $amount =
                (float)($data['amount'] ?? 0);

            $escrowFee =
                (float)($data['escrow_fee'] ?? 0);

            $sellerAmount =
                (float)($data['seller_amount'] ?? 0);

            $releaseCode = trim((string)($data['release_code'] ?? ''));

            /* Generate the internal release code when the service does not
             * provide one. It is never exposed by public API responses. */
            if ($releaseCode === '') {
                $releaseCode = (string)random_int(100000, 999999);
            }

            if (
                $reference === ''
                || $listingId <= 0
                || $buyerId <= 0
                || $sellerId <= 0
                || $amount <= 0
            ) {

                Logger::write(
                    'escrow_model_error',
                    [
                        'step' => 'CREATE_INVALID_DATA',
                        'data' => $data,
                    ]
                );

                return false;
            }

            $stmt =
                $this->db->prepare(
                    "
                    INSERT INTO {$this->table}
                    (
                        reference,
                        listing_id,

                        buyer_id,
                        seller_id,

                        buyer_phone,
                        seller_phone,

                        amount,
                        escrow_fee,
                        seller_amount,

                        currency,

                        payment_method,
                        delivery_type,
                        payment_reference,

                        release_code,

                        status,

                        expires_at
                    )

                    VALUES
                    (
                        :reference,
                        :listing_id,

                        :buyer_id,
                        :seller_id,

                        :buyer_phone,
                        :seller_phone,

                        :amount,
                        :escrow_fee,
                        :seller_amount,

                        :currency,

                        :payment_method,
                        :delivery_type,
                        :payment_reference,

                        :release_code,

                        :status,

                        :expires_at
                    )
                    "
                );

            $stmt->execute(
                [
                    'reference' =>
                        $reference,

                    'listing_id' =>
                        $listingId,

                    'buyer_id' =>
                        $buyerId,

                    'seller_id' =>
                        $sellerId,

                    'buyer_phone' =>
                        $data['buyer_phone']
                        ?? null,

                    'seller_phone' =>
                        $data['seller_phone']
                        ?? null,

                    'amount' =>
                        $amount,

                    'escrow_fee' =>
                        $escrowFee,

                    'seller_amount' =>
                        $sellerAmount,

                    'currency' =>
                        strtoupper(
                            trim(
                                (string)(
                                    $data['currency']
                                    ?? 'NGN'
                                )
                            )
                        ),

                    'payment_method' =>
                        strtolower(
                            trim(
                                (string)(
                                    $data['payment_method']
                                    ?? 'paystack'
                                )
                            )
                        ),

                    'delivery_type' =>
                        strtolower(
                            trim(
                                (string)(
                                    $data['delivery_type']
                                    ?? 'physical'
                                )
                            )
                        ),

                    'payment_reference' =>
                        $data['payment_reference']
                        ?? null,

                    'release_code' =>
                        $releaseCode,

                    'status' =>
                        strtolower(
                            trim(
                                (string)(
                                    $data['status']
                                    ?? 'pending'
                                )
                            )
                        ),

                    'expires_at' =>
                        $data['expires_at']
                        ?? null,
                ]
            );

            $id =
                (int)$this->db->lastInsertId();

            Logger::write(
                'escrow_model',
                [
                    'step' => 'CREATE_SUCCESS',
                    'escrow_id' => $id,
                    'reference' => $reference,
                ]
            );

            return $id;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'CREATE_EXCEPTION',
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return false;
        }
    }


    /**
     * ---------------------------------------------------------
     * Find Escrow By ID
     * ---------------------------------------------------------
     */
    public function find(int $id): ?array
    {
        try {

            if ($id <= 0) {
                return null;
            }

            $stmt =
                $this->db->prepare(
                    "
                    SELECT *
                    FROM {$this->table}
                    WHERE id = ?
                    LIMIT 1
                    "
                );

            $stmt->execute(
                [$id]
            );

            $row =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

            return $row ?: null;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'FIND_EXCEPTION',
                    'id' => $id,
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                ]
            );

            return null;
        }
    }


    /**
     * ---------------------------------------------------------
     * Find Escrow By Public Reference
     * ---------------------------------------------------------
     */
    public function findByReference(
        string $reference
    ): ?array {

        try {

            $reference =
                strtoupper(
                    trim($reference)
                );

            if ($reference === '') {
                return null;
            }

            $stmt =
                $this->db->prepare(
                    "
                    SELECT *
                    FROM {$this->table}
                    WHERE reference = ?
                    LIMIT 1
                    "
                );

            $stmt->execute(
                [$reference]
            );

            $row =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

            return $row ?: null;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'FIND_BY_REFERENCE_EXCEPTION',
                    'reference' => $reference,
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                ]
            );

            return null;
        }
    }


    /**
     * ---------------------------------------------------------
     * Update Escrow
     * ---------------------------------------------------------
     */
    public function update(
        int $id,
        array $data
    ): bool {

        try {

            if ($id <= 0 || empty($data)) {
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
             * Never allow arbitrary SQL fragments through update().
             */
            $allowedFields = [
                'reference',
                'listing_id',
                'buyer_id',
                'seller_id',
                'buyer_phone',
                'seller_phone',
                'amount',
                'escrow_fee',
                'seller_amount',
                'currency',
                'payment_method',
                'delivery_type',
                'payment_reference',
                'release_code',
                'status',
                'expires_at',
                'released_at',
                'cancelled_at',
                'buyer_confirmed_at',
                'seller_confirmed_at',
            ];

            $fields = [];
            $params = [];

            foreach ($data as $key => $value) {

                if (
                    !in_array(
                        $key,
                        $allowedFields,
                        true
                    )
                ) {
                    continue;
                }

                if (
                    $key === 'status'
                    &&
                    trim((string)$value) === ''
                ) {
                    continue;
                }

                $fields[] =
                    "{$key} = :{$key}";

                $params[$key] =
                    $value;
            }

            if (empty($fields)) {
                return false;
            }

            $fields[] =
                'updated_at = NOW()';

            $params['id'] =
                $id;

            $sql =
                "
                UPDATE {$this->table}
                SET
                    " .
                implode(
                    ",\n",
                    $fields
                ) .
                "
                WHERE id = :id
                ";

            $stmt =
                $this->db->prepare(
                    $sql
                );

            $result =
                $stmt->execute(
                    $params
                );

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
                    'step' => 'UPDATE_EXCEPTION',
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


    /**
     * ---------------------------------------------------------
     * Update Status
     * ---------------------------------------------------------
     */
    public function updateStatus(
        int $id,
        string $status
    ): bool {

        try {

            $status =
                strtolower(
                    trim($status)
                );

            if (
                $id <= 0
                ||
                $status === ''
            ) {
                return false;
            }

            Logger::write(
                'escrow_model',
                [
                    'step' => 'UPDATE_STATUS_START',
                    'id' => $id,
                    'status' => $status,
                ]
            );

            $stmt =
                $this->db->prepare(
                    "
                    UPDATE {$this->table}

                    SET
                        status = :status,
                        updated_at = NOW()

                    WHERE id = :id
                    "
                );

            $result =
                $stmt->execute(
                    [
                        'status' => $status,
                        'id' => $id,
                    ]
                );

            Logger::write(
                'escrow_model',
                [
                    'step' => 'UPDATE_STATUS_RESULT',
                    'id' => $id,
                    'status' => $status,
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
                    'step' => 'UPDATE_STATUS_EXCEPTION',
                    'id' => $id,
                    'status' => $status,
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                ]
            );

            return false;
        }
    }


    /**
     * ---------------------------------------------------------
     * Mark Escrow Paid
     * ---------------------------------------------------------
     *
     * Safe payment transition:
     *
     *     pending -> paid
     *
     * Rules:
     *
     * 1. Empty references are rejected.
     * 2. Existing different payment references cannot be replaced.
     * 3. Repeated webhook for the same payment is idempotent.
     * 4. Advanced escrow states are never moved backwards.
     * 5. The actual transition is atomic.
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

            if (
                $id <= 0
                ||
                $paymentReference === ''
            ) {

                Logger::write(
                    'escrow_model_error',
                    [
                        'step' =>
                            'MARK_PAID_INVALID_ARGUMENTS',

                        'escrow_id' =>
                            $id,

                        'payment_reference' =>
                            $paymentReference,
                    ]
                );

                return false;
            }

            Logger::write(
                'escrow_model',
                [
                    'step' =>
                        'MARK_PAID_START',

                    'escrow_id' =>
                        $id,

                    'payment_reference' =>
                        $paymentReference,
                ]
            );

            /*
             * First inspect the current state.
             */
            $escrow =
                $this->find($id);

            if (!$escrow) {

                Logger::write(
                    'escrow_model_error',
                    [
                        'step' =>
                            'MARK_PAID_ESCROW_NOT_FOUND',

                        'escrow_id' =>
                            $id,
                    ]
                );

                return false;
            }

            $currentStatus =
                strtolower(
                    trim(
                        (string)(
                            $escrow['status']
                            ?? ''
                        )
                    )
                );

            $existingReference =
                trim(
                    (string)(
                        $escrow['payment_reference']
                        ?? ''
                    )
                );

            /*
             * Same reference + already paid:
             * idempotent success.
             */
            if (
                $existingReference !== ''
                &&
                hash_equals(
                    $existingReference,
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
                        'step' =>
                            'MARK_PAID_ALREADY_PROCESSED',

                        'escrow_id' =>
                            $id,

                        'status' =>
                            $currentStatus,

                        'payment_reference' =>
                            $existingReference,
                    ]
                );

                return true;
            }

            /*
             * A different Paystack reference already exists.
             * Never overwrite it.
             */
            if (
                $existingReference !== ''
                &&
                !hash_equals(
                    $existingReference,
                    $paymentReference
                )
            ) {

                Logger::write(
                    'escrow_model_error',
                    [
                        'step' =>
                            'MARK_PAID_REFERENCE_CONFLICT',

                        'escrow_id' =>
                            $id,

                        'existing_reference' =>
                            $existingReference,

                        'incoming_reference' =>
                            $paymentReference,
                    ]
                );

                return false;
            }

            /*
             * If payment is already beyond "paid", don't move
             * the escrow backwards.
             *
             * If no payment reference was recorded, attach it.
             */
            if (
                in_array(
                    $currentStatus,
                    $this->advancedStatuses,
                    true
                )
            ) {

                Logger::write(
                    'escrow_model',
                    [
                        'step' =>
                            'MARK_PAID_ADVANCED_STATUS',

                        'escrow_id' =>
                            $id,

                        'status' =>
                            $currentStatus,
                    ]
                );

                if (
                    $existingReference === ''
                ) {

                    $stmt =
                        $this->db->prepare(
                            "
                            UPDATE {$this->table}

                            SET
                                payment_reference =
                                    :payment_reference,
                                updated_at = NOW()

                            WHERE id = :id
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

                    return true;
                }

                return true;
            }

            /*
             * Only pending can become paid.
             */
            if (
                $currentStatus !== 'pending'
            ) {

                Logger::write(
                    'escrow_model_error',
                    [
                        'step' =>
                            'MARK_PAID_INVALID_STATUS',

                        'escrow_id' =>
                            $id,

                        'status' =>
                            $currentStatus,
                    ]
                );

                return false;
            }

            /*
             * Atomic transition.
             *
             * This protects against two Paystack webhook requests
             * arriving at almost exactly the same time.
             */
            $stmt =
                $this->db->prepare(
                    "
                    UPDATE {$this->table}

                    SET
                        payment_reference =
                            :payment_reference,

                        status =
                            'paid',

                        updated_at =
                            NOW()

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
                    'step' =>
                        'MARK_PAID_ATOMIC_RESULT',

                    'escrow_id' =>
                        $id,

                    'payment_reference' =>
                        $paymentReference,

                    'rows_affected' =>
                        $affected,
                ]
            );

            /*
             * Reload and verify the actual database state.
             */
            $after =
                $this->find($id);

            if (!$after) {

                Logger::write(
                    'escrow_model_error',
                    [
                        'step' =>
                            'MARK_PAID_RELOAD_FAILED',

                        'escrow_id' =>
                            $id,
                    ]
                );

                return false;
            }

            $afterStatus =
                strtolower(
                    trim(
                        (string)(
                            $after['status']
                            ?? ''
                        )
                    )
                );

            $afterReference =
                trim(
                    (string)(
                        $after['payment_reference']
                        ?? ''
                    )
                );

            /*
             * Successful final state.
             */
            if (
                in_array(
                    $afterStatus,
                    $this->paidStatuses,
                    true
                )
                &&
                $afterReference !== ''
                &&
                hash_equals(
                    $afterReference,
                    $paymentReference
                )
            ) {

                Logger::write(
                    'escrow_model',
                    [
                        'step' =>
                            'MARK_PAID_CONFIRMED',

                        'escrow_id' =>
                            $id,

                        'status' =>
                            $afterStatus,

                        'payment_reference' =>
                            $afterReference,
                    ]
                );

                return true;
            }

            Logger::write(
                'escrow_model_error',
                [
                    'step' =>
                        'MARK_PAID_FAILED',

                    'escrow_id' =>
                        $id,

                    'payment_reference' =>
                        $paymentReference,

                    'after_status' =>
                        $afterStatus,

                    'after_reference' =>
                        $afterReference,
                ]
            );

            return false;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' =>
                        'MARK_PAID_EXCEPTION',

                    'escrow_id' =>
                        $id,

                    'payment_reference' =>
                        $paymentReference,

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

            return false;
        }
    }


    /**
     * ---------------------------------------------------------
     * Release Funds
     * ---------------------------------------------------------
     */
    public function release(
        int $id
    ): bool {

        try {

            if ($id <= 0) {
                return false;
            }

            Logger::write(
                'escrow_model',
                [
                    'step' => 'RELEASE_START',
                    'escrow_id' => $id,
                ]
            );

            $stmt =
                $this->db->prepare(
                    "
                    UPDATE {$this->table}

                    SET
                        status = 'completed',
                        released_at = NOW(),
                        updated_at = NOW()

                    WHERE id = :id

                    AND status IN
                    (
                        'awaiting_payout',
                        'buyer_confirmed'
                    )
                    "
                );

            $stmt->execute(
                [
                    'id' => $id,
                ]
            );

            $success =
                $stmt->rowCount() > 0;

            Logger::write(
                'escrow_model',
                [
                    'step' => 'RELEASE_RESULT',
                    'escrow_id' => $id,
                    'success' => $success,
                ]
            );

            return $success;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'RELEASE_EXCEPTION',
                    'escrow_id' => $id,
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                ]
            );

            return false;
        }
    }


    /**
     * ---------------------------------------------------------
     * Cancel Escrow
     * ---------------------------------------------------------
     */
    public function cancel(
        int $id
    ): bool {

        try {

            if ($id <= 0) {
                return false;
            }

            Logger::write(
                'escrow_model',
                [
                    'step' => 'CANCEL_START',
                    'escrow_id' => $id,
                ]
            );

            $stmt =
                $this->db->prepare(
                    "
                    UPDATE {$this->table}

                    SET
                        status = 'cancelled',
                        cancelled_at = NOW(),
                        updated_at = NOW()

                    WHERE id = :id

                    AND status IN
                    (
                        'pending',
                        'paid'
                    )
                    "
                );

            $stmt->execute(
                [
                    'id' => $id,
                ]
            );

            $success =
                $stmt->rowCount() > 0;

            Logger::write(
                'escrow_model',
                [
                    'step' => 'CANCEL_RESULT',
                    'escrow_id' => $id,
                    'success' => $success,
                ]
            );

            return $success;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'CANCEL_EXCEPTION',
                    'escrow_id' => $id,
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                ]
            );

            return false;
        }
    }


    /**
     * ---------------------------------------------------------
     * Buyer Confirmed Item
     * ---------------------------------------------------------
     */
    public function buyerConfirm(
        int $id
    ): bool {

        try {

            if ($id <= 0) {
                return false;
            }

            $stmt =
                $this->db->prepare(
                    "
                    UPDATE {$this->table}

                    SET
                        status = 'buyer_confirmed',
                        buyer_confirmed_at = NOW(),
                        updated_at = NOW()

                    WHERE id = :id

                    AND status = 'item_sent'
                    "
                );

            $stmt->execute(
                [
                    'id' => $id,
                ]
            );

            return $stmt->rowCount() > 0;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'BUYER_CONFIRM_EXCEPTION',
                    'escrow_id' => $id,
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                ]
            );

            return false;
        }
    }


    /**
     * ---------------------------------------------------------
     * Seller Confirmed
     * ---------------------------------------------------------
     */
    public function sellerConfirm(
        int $id
    ): bool {

        try {

            if ($id <= 0) {
                return false;
            }

            return $this->update(
                $id,
                [
                    'seller_confirmed_at' =>
                        date('Y-m-d H:i:s'),
                ]
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' =>
                        'SELLER_CONFIRM_EXCEPTION',

                    'escrow_id' =>
                        $id,

                    'message' =>
                        $e->getMessage(),

                    'line' =>
                        $e->getLine(),
                ]
            );

            return false;
        }
    }


    /**
     * ---------------------------------------------------------
     * Buyer Escrows
     * ---------------------------------------------------------
     */
    public function byBuyer(
        int $buyerId
    ): array {

        try {

            if ($buyerId <= 0) {
                return [];
            }

            $stmt =
                $this->db->prepare(
                    "
                    SELECT *
                    FROM {$this->table}
                    WHERE buyer_id = ?
                    ORDER BY id DESC
                    "
                );

            $stmt->execute(
                [$buyerId]
            );

            return
                $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'BY_BUYER_EXCEPTION',
                    'buyer_id' => $buyerId,
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                ]
            );

            return [];
        }
    }


    /**
     * ---------------------------------------------------------
     * Seller Escrows
     * ---------------------------------------------------------
     */
    public function bySeller(
        int $sellerId
    ): array {

        try {

            if ($sellerId <= 0) {
                return [];
            }

            $stmt =
                $this->db->prepare(
                    "
                    SELECT *
                    FROM {$this->table}
                    WHERE seller_id = ?
                    ORDER BY id DESC
                    "
                );

            $stmt->execute(
                [$sellerId]
            );

            return
                $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'BY_SELLER_EXCEPTION',
                    'seller_id' => $sellerId,
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                ]
            );

            return [];
        }
    }


    /**
     * ---------------------------------------------------------
     * Escrow Statistics
     * ---------------------------------------------------------
     */
    public function statistics(
        int $userId
    ): array {

        try {

            if ($userId <= 0) {
                return [
                    'total' => 0,
                    'amount' => 0,
                ];
            }

            $stmt =
                $this->db->prepare(
                    "
                    SELECT

                        COUNT(*) AS total,

                        COALESCE(
                            SUM(amount),
                            0
                        ) AS amount

                    FROM {$this->table}

                    WHERE
                        buyer_id = ?
                        OR seller_id = ?
                    "
                );

            $stmt->execute(
                [
                    $userId,
                    $userId,
                ]
            );

            return
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                )
                ?: [
                    'total' => 0,
                    'amount' => 0,
                ];

        } catch (Throwable $e) {

            Logger::write(
                'escrow_model_error',
                [
                    'step' => 'STATISTICS_EXCEPTION',
                    'user_id' => $userId,
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                ]
            );

            return [
                'total' => 0,
                'amount' => 0,
            ];
        }
    }
}