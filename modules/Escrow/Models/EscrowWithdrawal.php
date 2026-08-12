<?php

declare(strict_types=1);

namespace Modules\Escrow\Models;

use Core\Database;
use Core\Logger;
use PDO;
use Throwable;

class EscrowWithdrawal
{
    protected string $table = 'escrow_withdrawals';

    /**
     * Create withdrawal request
     */
    public function create(array $data): ?array
    {
        try {

            $db = Database::connect();

            $stmt = $db->prepare("
                INSERT INTO {$this->table}
                (
                    seller_id,
                    wallet_id,
                    amount,
                    reference,
                    status,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    :seller_id,
                    :wallet_id,
                    :amount,
                    :reference,
                    :status,
                    NOW(),
                    NOW()
                )
            ");

            $stmt->execute([

                'seller_id'=>$data['seller_id'],
                'wallet_id'=>$data['wallet_id'],
                'amount'=>$data['amount'],
                'reference'=>$data['reference'],
                'status'=>$data['status'] ?? 'pending'

            ]);

            return $this->find(
                (int)$db->lastInsertId()
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_withdrawal_error',
                [
                    'message'=>$e->getMessage()
                ]
            );

            return null;
        }
    }

    /**
     * Find request
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
     * Update
     */
    public function update(
        int $id,
        array $data
    ): bool {

        $db = Database::connect();

        $fields=[];

        foreach($data as $key=>$value){

            $fields[]="{$key}=:{$key}";

        }

        $fields[]="updated_at=NOW()";

        $sql="

            UPDATE {$this->table}

            SET ".implode(',', $fields)."

            WHERE id=:id

        ";

        $stmt=$db->prepare($sql);

        $data['id']=$id;

        return $stmt->execute($data);
    }

    /**
     * Pending withdrawals
     */
    public function pending(): array
    {
        $db = Database::connect();

        $stmt = $db->query("
            SELECT *
            FROM {$this->table}
            WHERE status='pending'
            ORDER BY id ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Seller history
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

    /**
     * Mark approved
     */
    public function approve(
        int $id
    ): bool {

        return $this->update(
            $id,
            [
                'status'=>'approved'
            ]
        );

    }

    /**
     * Mark rejected
     */
    public function reject(
        int $id
    ): bool {

        return $this->update(
            $id,
            [
                'status'=>'rejected'
            ]
        );

    }

    /**
     * Mark paid
     */
    public function paid(
        int $id
    ): bool {

        return $this->update(
            $id,
            [
                'status'=>'paid'
            ]
        );

    }
}