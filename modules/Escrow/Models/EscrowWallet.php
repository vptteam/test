<?php

declare(strict_types=1);

namespace Modules\Escrow\Models;

use Core\Database;
use Core\Logger;
use PDO;
use Throwable;

class EscrowWallet
{
    /**
     * ---------------------------------------------------------
     * TABLE
     * ---------------------------------------------------------
     */
    protected string $table = 'escrow_wallets';

    /**
     * ---------------------------------------------------------
     * DATABASE CONNECTION
     * ---------------------------------------------------------
     */
    protected function db(): PDO
    {
        return Database::getInstance()->connection();
    }

    /**
     * ---------------------------------------------------------
     * FIND WALLET BY ID
     * ---------------------------------------------------------
     */
    public function find(
        int $id
    ): ?array {

        try {

            $stmt = $this->db()->prepare(
                "
                SELECT *
                FROM {$this->table}
                WHERE id = ?
                LIMIT 1
                "
            );

            $stmt->execute([
                $id
            ]);

            $wallet = $stmt->fetch(
                PDO::FETCH_ASSOC
            );

            return $wallet ?: null;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'    => 'FIND',
                    'wallet_id' => $id,
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine()
                ]
            );

            return null;
        }
    }

    /**
     * ---------------------------------------------------------
     * FIND WALLET BY SELLER
     * ---------------------------------------------------------
     */
    public function findBySeller(
        int $sellerId
    ): ?array {

        try {

            $stmt = $this->db()->prepare(
                "
                SELECT *
                FROM {$this->table}
                WHERE seller_id = ?
                LIMIT 1
                "
            );

            $stmt->execute([
                $sellerId
            ]);

            $wallet = $stmt->fetch(
                PDO::FETCH_ASSOC
            );

            return $wallet ?: null;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'      => 'FIND_BY_SELLER',
                    'seller_id' => $sellerId,
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine()
                ]
            );

            return null;
        }
    }

    /**
     * ---------------------------------------------------------
     * CREATE SELLER WALLET
     * ---------------------------------------------------------
     *
     * Supports:
     *
     * - seller_id
     * - bank_code
     * - bank_name
     * - account_number
     * - account_name
     * - recipient_code
     * - status
     * - verified_at
     * - created_at
     * - updated_at
     *
     * The EscrowWalletService sends status = verified.
     */
    public function create(
        array $data
    ): bool {

        try {

            Logger::write(
                'escrow_wallet',
                [
                    'step'           => 'CREATE_START',
                    'seller_id'      => $data['seller_id'] ?? null,
                    'bank_code'      => $data['bank_code'] ?? null,
                    'bank_name'      => $data['bank_name'] ?? null,
                    'account_name'   => $data['account_name'] ?? null,
                    'status'         => $data['status'] ?? null
                ]
            );

            /*
            |---------------------------------------------------------
            | Default status
            |---------------------------------------------------------
            |
            | If the service does not explicitly provide a status,
            | default to verified because this wallet is created only
            | after Paystack account verification.
            |
            */
            $status = trim(
                (string)(
                    $data['status']
                    ?? 'verified'
                )
            );

            if ($status === '') {
                $status = 'verified';
            }

            $stmt = $this->db()->prepare(
                "
                INSERT INTO {$this->table}
                (
                    seller_id,
                    bank_code,
                    bank_name,
                    account_number,
                    account_name,
                    recipient_code,
                    status,
                    verified_at,
                    created_at,
                    updated_at
                )

                VALUES
                (
                    :seller_id,
                    :bank_code,
                    :bank_name,
                    :account_number,
                    :account_name,
                    :recipient_code,
                    :status,
                    :verified_at,
                    NOW(),
                    NOW()
                )
                "
            );

            $result = $stmt->execute([

                'seller_id' =>
                    (int)$data['seller_id'],

                'bank_code' =>
                    trim(
                        (string)$data['bank_code']
                    ),

                'bank_name' =>
                    trim(
                        (string)$data['bank_name']
                    ),

                'account_number' =>
                    trim(
                        (string)$data['account_number']
                    ),

                'account_name' =>
                    trim(
                        (string)$data['account_name']
                    ),

                'recipient_code' =>
                    !empty($data['recipient_code'])
                        ? trim(
                            (string)$data['recipient_code']
                        )
                        : null,

                'status' =>
                    $status,

                'verified_at' =>
                    $data['verified_at']
                    ?? null
            ]);

            Logger::write(
                'escrow_wallet',
                [
                    'step'      => 'CREATE_RESULT',
                    'seller_id' => $data['seller_id'] ?? null,
                    'success'   => $result,
                    'status'    => $status
                ]
            );

            return $result;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'      => 'CREATE_FAILED',
                    'seller_id' => $data['seller_id'] ?? null,
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine()
                ]
            );

            return false;
        }
    }

    /**
     * ---------------------------------------------------------
     * UPDATE WALLET
     * ---------------------------------------------------------
     *
     * Supports dynamic updates including:
     *
     * - bank_code
     * - bank_name
     * - account_number
     * - account_name
     * - recipient_code
     * - status
     * - verified_at
     */
    public function update(
        int $id,
        array $data
    ): bool {

        try {

            if ($id <= 0) {
                return false;
            }

            if (empty($data)) {
                return false;
            }

            /*
            |---------------------------------------------------------
            | Allowed columns
            |---------------------------------------------------------
            |
            | Prevent accidental SQL column injection through the
            | dynamic update method.
            |
            */
            $allowedColumns = [

                'seller_id',

                'bank_code',

                'bank_name',

                'account_number',

                'account_name',

                'recipient_code',

                'status',

                'verified_at'

            ];

            $fields = [];

            $params = [];

            foreach ($data as $column => $value) {

                if (
                    !in_array(
                        $column,
                        $allowedColumns,
                        true
                    )
                ) {
                    continue;
                }

                $fields[] =
                    "{$column} = :{$column}";

                $params[$column] = $value;
            }

            if (empty($fields)) {
                return false;
            }

            /*
            |---------------------------------------------------------
            | Always update updated_at
            |---------------------------------------------------------
            */
            $fields[] =
                "updated_at = NOW()";

            $sql = "
                UPDATE {$this->table}

                SET
                    " . implode(
                        ", ",
                        $fields
                    ) . "

                WHERE id = :id
            ";

            $params['id'] = $id;

            $stmt = $this->db()->prepare(
                $sql
            );

            $result = $stmt->execute(
                $params
            );

            Logger::write(
                'escrow_wallet',
                [
                    'step'      => 'UPDATE',
                    'wallet_id' => $id,
                    'fields'    => array_keys($params),
                    'success'   => $result
                ]
            );

            return $result;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'      => 'UPDATE_FAILED',
                    'wallet_id' => $id,
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine()
                ]
            );

            return false;
        }
    }

    /**
     * ---------------------------------------------------------
     * DELETE WALLET
     * ---------------------------------------------------------
     */
    public function delete(
        int $id
    ): bool {

        try {

            $stmt = $this->db()->prepare(
                "
                DELETE
                FROM {$this->table}
                WHERE id = ?
                "
            );

            $result = $stmt->execute([
                $id
            ]);

            Logger::write(
                'escrow_wallet',
                [
                    'step'      => 'DELETE',
                    'wallet_id' => $id,
                    'success'   => $result
                ]
            );

            return $result;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'      => 'DELETE_FAILED',
                    'wallet_id' => $id,
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine()
                ]
            );

            return false;
        }
    }

    /**
     * ---------------------------------------------------------
     * FIND WALLET BY RECIPIENT CODE
     * ---------------------------------------------------------
     */
    public function findByRecipient(
        string $recipientCode
    ): ?array {

        try {

            $recipientCode = trim(
                $recipientCode
            );

            if ($recipientCode === '') {
                return null;
            }

            $stmt = $this->db()->prepare(
                "
                SELECT *
                FROM {$this->table}
                WHERE recipient_code = ?
                LIMIT 1
                "
            );

            $stmt->execute([
                $recipientCode
            ]);

            $wallet = $stmt->fetch(
                PDO::FETCH_ASSOC
            );

            return $wallet ?: null;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'           => 'FIND_BY_RECIPIENT',
                    'recipient_code' => $recipientCode,
                    'message'        => $e->getMessage(),
                    'line'           => $e->getLine()
                ]
            );

            return null;
        }
    }

    /**
     * ---------------------------------------------------------
     * SELLER HAS VERIFIED WALLET
     * ---------------------------------------------------------
     *
     * A wallet is considered verified only when:
     *
     * 1. Wallet exists
     * 2. status = verified
     * 3. verified_at exists
     * 4. recipient_code exists
     */
    public function verified(
        int $sellerId
    ): bool {

        $wallet = $this->findBySeller(
            $sellerId
        );

        if (!$wallet) {
            return false;
        }

        if (
            ($wallet['status'] ?? '')
            !== 'verified'
        ) {
            return false;
        }

        if (
            empty(
                $wallet['verified_at']
            )
        ) {
            return false;
        }

        if (
            empty(
                $wallet['recipient_code']
            )
        ) {
            return false;
        }

        return true;
    }

    /**
     * ---------------------------------------------------------
     * WALLET EXISTS
     * ---------------------------------------------------------
     */
    public function exists(
        int $sellerId
    ): bool {

        return $this->findBySeller(
            $sellerId
        ) !== null;
    }

    /**
     * ---------------------------------------------------------
     * COUNT REGISTERED WALLETS
     * ---------------------------------------------------------
     */
    public function count(): int
    {

        try {

            $stmt = $this->db()->query(
                "
                SELECT COUNT(*)
                FROM {$this->table}
                "
            );

            return (int)$stmt->fetchColumn();

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'    => 'COUNT',
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine()
                ]
            );

            return 0;
        }
    }

    /**
     * ---------------------------------------------------------
     * ALL WALLETS
     * ---------------------------------------------------------
     */
    public function all(): array
    {

        try {

            $stmt = $this->db()->query(
                "
                SELECT *
                FROM {$this->table}
                ORDER BY id DESC
                "
            );

            return $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'    => 'ALL',
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine()
                ]
            );

            return [];
        }
    }

    /**
     * ---------------------------------------------------------
     * VERIFIED WALLETS
     * ---------------------------------------------------------
     *
     * Only wallets that are actually marked verified are
     * returned.
     */
    public function verifiedWallets(): array
    {

        try {

            $stmt = $this->db()->query(
                "
                SELECT *
                FROM {$this->table}

                WHERE
                    status = 'verified'

                    AND verified_at IS NOT NULL

                    AND recipient_code IS NOT NULL

                    AND recipient_code <> ''

                ORDER BY id DESC
                "
            );

            return $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'    => 'VERIFIED_WALLETS',
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine()
                ]
            );

            return [];
        }
    }

    /**
     * ---------------------------------------------------------
     * UPDATE WALLET STATUS
     * ---------------------------------------------------------
     *
     * Convenience method for changing wallet status.
     */
    public function updateStatus(
        int $id,
        string $status
    ): bool {

        try {

            $status = trim(
                strtolower($status)
            );

            if ($status === '') {
                return false;
            }

            Logger::write(
                'escrow_wallet',
                [
                    'step'      => 'UPDATE_STATUS',
                    'wallet_id' => $id,
                    'status'    => $status
                ]
            );

            return $this->update(
                $id,
                [
                    'status' => $status
                ]
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'      => 'UPDATE_STATUS_FAILED',
                    'wallet_id' => $id,
                    'status'    => $status ?? null,
                    'message'   => $e->getMessage(),
                    'line'      => $e->getLine()
                ]
            );

            return false;
        }
    }

    /**
     * ---------------------------------------------------------
     * MARK WALLET VERIFIED
     * ---------------------------------------------------------
     */
    public function markVerified(
        int $id
    ): bool {

        try {

            return $this->update(
                $id,
                [
                    'status'      => 'verified',
                    'verified_at' => date(
                        'Y-m-d H:i:s'
                    )
                ]
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'      => 'MARK_VERIFIED_FAILED',
                    'wallet_id' => $id,
                    'message'   => $e->getMessage(),
                    'line'      => $e->getLine()
                ]
            );

            return false;
        }
    }

    /**
     * ---------------------------------------------------------
     * MARK WALLET PENDING
     * ---------------------------------------------------------
     */
    public function markPending(
        int $id
    ): bool {

        try {

            return $this->update(
                $id,
                [
                    'status' => 'pending'
                ]
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'      => 'MARK_PENDING_FAILED',
                    'wallet_id' => $id,
                    'message'   => $e->getMessage(),
                    'line'      => $e->getLine()
                ]
            );

            return false;
        }
    }
}
