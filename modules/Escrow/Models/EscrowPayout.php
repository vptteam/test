<?php

declare(strict_types=1);

namespace Modules\Escrow\Models;

use Core\Database;
use Core\Logger;
use PDO;
use Throwable;

class EscrowPayout
{
    protected string $table = 'escrow_payouts';

    /**
     * Create payout record
     */
    public function create(array $data): ?array
    {
        try {

            $db = Database::connect();

            $stmt = $db->prepare("
                INSERT INTO {$this->table}
                (
                    escrow_id,
                    seller_id,
                    wallet_id,
                    amount,
                    fee,
                    reference,
                    transfer_code,
                    status,
                    initiated_by,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    :escrow_id,
                    :seller_id,
                    :wallet_id,
                    :amount,
                    :fee,
                    :reference,
                    :transfer_code,
                    :status,
                    :initiated_by,
                    NOW(),
                    NOW()
                )
            ");

            $stmt->execute([

                'escrow_id'     => $data['escrow_id'],
                'seller_id'     => $data['seller_id'],
                'wallet_id'     => $data['wallet_id'],
                'amount'        => $data['amount'],
                'fee'           => $data['fee'] ?? 0,
                'reference'     => $data['reference'],
                'transfer_code' => $data['transfer_code'] ?? null,
                'status'        => $data['status'] ?? 'pending',
                'initiated_by'  => $data['initiated_by'] ?? null

            ]);

            return $this->find(
                (int)$db->lastInsertId()
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_payout_error',
                [
                    'message'=>$e->getMessage(),
                    'line'=>$e->getLine()
                ]
            );

            return null;
        }
    }

    /**
     * Find payout
     */
    public function find(int $id): ?array
    {
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
    }

    /**
     * Find by reference
     */
    public function findByReference(string $reference): ?array
    {
        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT *
            FROM {$this->table}
            WHERE reference=?
            LIMIT 1
        ");

        $stmt->execute([$reference]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Update payout
     */
    public function update(
        int $id,
        array $data
    ): bool {

        $db = Database::connect();

        $fields = [];

        foreach ($data as $key=>$value) {

            $fields[] = "{$key}=:{$key}";

        }

        $fields[] = "updated_at=NOW()";

        $sql = "

            UPDATE {$this->table}

            SET ".implode(',', $fields)."

            WHERE id=:id

        ";

        $stmt = $db->prepare($sql);

        $data['id']=$id;

        return $stmt->execute($data);
    }

    /**
     * Mark processing
     */
    public function markProcessing(
        int $id,
        string $transferCode
    ): bool {

        return $this->update(
            $id,
            [
                'status'=>'processing',
                'transfer_code'=>$transferCode
            ]
        );

    }

    /**
     * Mark success
     */
    public function markSuccess(int $id): bool
    {
        return $this->update(
            $id,
            [
                'status'=>'success',
                'processed_at'=>date('Y-m-d H:i:s')
            ]
        );
    }

    /**
     * Mark failed
     */
    public function markFailed(int $id): bool
    {
        return $this->update(
            $id,
            [
                'status'=>'failed'
            ]
        );
    }

    /**
     * Escrow payout history
     */
    public function byEscrow(
        int $escrowId
    ): array {

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT *
            FROM {$this->table}
            WHERE escrow_id=?
            ORDER BY id DESC
        ");

        $stmt->execute([$escrowId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    }

    /**
     * Seller payout history
     */
    public function bySeller(
        int $sellerId
    ): array {

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT *
            FROM {$this->table}
            WHERE seller_id=?
            ORDER BY id DESC
        ");

        $stmt->execute([$sellerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    }
}