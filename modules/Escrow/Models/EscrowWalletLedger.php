<?php

declare(strict_types=1);

namespace Modules\Escrow\Models;

use Core\Database;
use Core\Logger;
use PDO;
use Throwable;

class EscrowWalletLedger
{
    protected string $table = 'escrow_wallet_ledger';

    /**
     * Create Ledger Entry
     */
    public function create(array $data): ?array
    {
        try {

            $db = Database::connect();

            $stmt = $db->prepare("
                INSERT INTO {$this->table}
                (
                    wallet_id,
                    user_id,
                    escrow_id,
                    withdrawal_id,
                    reference,
                    type,
                    amount,
                    balance_before,
                    balance_after,
                    narration,
                    created_at
                )

                VALUES
                (
                    :wallet_id,
                    :user_id,
                    :escrow_id,
                    :withdrawal_id,
                    :reference,
                    :type,
                    :amount,
                    :balance_before,
                    :balance_after,
                    :narration,
                    NOW()
                )
            ");

            $stmt->execute([

                'wallet_id'       => $data['wallet_id'],

                'user_id'         => $data['user_id'],

                'escrow_id'       => $data['escrow_id'] ?? null,

                'withdrawal_id'   => $data['withdrawal_id'] ?? null,

                'reference'       => $data['reference'] ?? null,

                'type'            => $data['type'],

                'amount'          => $data['amount'],

                'balance_before'  => $data['balance_before'],

                'balance_after'   => $data['balance_after'],

                'narration'       => $data['narration'] ?? null

            ]);

            Logger::write(
                'escrow_wallet_ledger',
                [
                    'step' => 'ENTRY_CREATED',
                    'wallet_id' => $data['wallet_id'],
                    'type' => $data['type'],
                    'amount' => $data['amount'],
                    'reference' => $data['reference'] ?? null
                ]
            );

            return $this->find(
                (int)$db->lastInsertId()
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_ledger_error',
                [
                    'step' => 'CREATE',
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]
            );

            return null;

        }
    }

    /**
     * Find Entry
     */
    public function find(int $id): ?array
    {
        try {

            $db = Database::connect();

            $stmt = $db->prepare("
                SELECT *
                FROM {$this->table}
                WHERE id=?
                LIMIT 1
            ");

            $stmt->execute([$id]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_ledger_error',
                [
                    'step' => 'FIND',
                    'message' => $e->getMessage()
                ]
            );

            return null;

        }
    }

    /**
     * Find By Reference
     */
    public function findByReference(
        string $reference
    ): array {

        try {

            $db = Database::connect();

            $stmt = $db->prepare("
                SELECT *
                FROM {$this->table}
                WHERE reference=?
                ORDER BY id ASC
            ");

            $stmt->execute([
                $reference
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_ledger_error',
                [
                    'step' => 'REFERENCE',
                    'message' => $e->getMessage()
                ]
            );

            return [];

        }
    }

    /**
     * Wallet History
     */
    public function walletHistory(
        int $walletId,
        int $limit = 50
    ): array {

        try {

            $limit = max(1, (int)$limit);

            $db = Database::connect();

            $stmt = $db->prepare("
                SELECT *
                FROM {$this->table}
                WHERE wallet_id=?
                ORDER BY id DESC
                LIMIT {$limit}
            ");

            $stmt->execute([
                $walletId
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_ledger_error',
                [
                    'step' => 'WALLET_HISTORY',
                    'message' => $e->getMessage()
                ]
            );

            return [];

        }
    }

    /**
     * User History
     */
    public function userHistory(
        int $userId,
        int $limit = 100
    ): array {

        try {

            $limit = max(1, (int)$limit);

            $db = Database::connect();

            $stmt = $db->prepare("
                SELECT *
                FROM {$this->table}
                WHERE user_id=?
                ORDER BY id DESC
                LIMIT {$limit}
            ");

            $stmt->execute([
                $userId
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_ledger_error',
                [
                    'step' => 'USER_HISTORY',
                    'message' => $e->getMessage()
                ]
            );

            return [];

        }
    }

    /**
     * Escrow History
     */
    public function escrowHistory(
        int $escrowId
    ): array {

        try {

            $db = Database::connect();

            $stmt = $db->prepare("
                SELECT *
                FROM {$this->table}
                WHERE escrow_id=?
                ORDER BY id ASC
            ");

            $stmt->execute([
                $escrowId
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_ledger_error',
                [
                    'step' => 'ESCROW_HISTORY',
                    'message' => $e->getMessage()
                ]
            );

            return [];

        }
    }

    /**
     * Withdrawal History
     */
    public function withdrawalHistory(
        int $withdrawalId
    ): array {

        try {

            $db = Database::connect();

            $stmt = $db->prepare("
                SELECT *
                FROM {$this->table}
                WHERE withdrawal_id=?
                ORDER BY id ASC
            ");

            $stmt->execute([
                $withdrawalId
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_ledger_error',
                [
                    'step' => 'WITHDRAWAL_HISTORY',
                    'message' => $e->getMessage()
                ]
            );

            return [];

        }
    }

    /**
     * Latest Transactions
     */
    public function latest(
        int $limit = 100
    ): array {

        try {

            $limit = max(1, (int)$limit);

            $db = Database::connect();

            $stmt = $db->query("
                SELECT *
                FROM {$this->table}
                ORDER BY id DESC
                LIMIT {$limit}
            ");

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_ledger_error',
                [
                    'step' => 'LATEST',
                    'message' => $e->getMessage()
                ]
            );

            return [];

        }
    }
}