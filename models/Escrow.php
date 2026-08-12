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

    public function __construct()
    {
        $this->db = Database::getInstance()->connection();
    }

    /**
     * Create Escrow
     */
    public function create(array $data): int|false
    {
        try {

            $stmt = $this->db->prepare("
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
            ");

            $stmt->execute([

                'reference'          => $data['reference'],

                'listing_id'         => $data['listing_id'],

                'buyer_id'           => $data['buyer_id'],
                'seller_id'          => $data['seller_id'],

                'buyer_phone'        => $data['buyer_phone'] ?? null,
                'seller_phone'       => $data['seller_phone'] ?? null,

                'amount'             => $data['amount'],
                'escrow_fee'         => $data['escrow_fee'],
                'seller_amount'      => $data['seller_amount'],

                'currency'           => $data['currency'] ?? 'NGN',

                'payment_method'     => $data['payment_method'] ?? 'paystack',
                'delivery_type'      => $data['delivery_type'] ?? 'physical',

                'payment_reference'  => $data['payment_reference'] ?? null,

                'release_code'       => $data['release_code'],

                'status'             => $data['status'] ?? 'pending',

                'expires_at'         => $data['expires_at'] ?? null,

            ]);

            return (int)$this->db->lastInsertId();

        } catch (Throwable $e) {

            Logger::write(
                'escrow_create_error',
                [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine()
                ]
            );

            return false;
        }
    }

    /**
     * Find by ID
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("
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
     * Find by Reference
     */
    public function findByReference(string $reference): ?array
    {
        $stmt = $this->db->prepare("
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
     * Update Status
     */
    public function updateStatus(
        int $id,
        string $status
    ): bool {

        try {

            $stmt = $this->db->prepare("
                UPDATE {$this->table}

                SET
                    status = ?,
                    updated_at = NOW()

                WHERE id = ?
            ");

            return $stmt->execute([
                $status,
                $id
            ]);

        } catch (Throwable $e) {

            Logger::write(
                'escrow_status_error',
                [
                    'message'=>$e->getMessage(),
                    'line'=>$e->getLine()
                ]
            );

            return false;
        }
    }

    /**
     * Mark Paid
     */
    public function markPaid(
        int $id,
        string $paymentReference
    ): bool {

        try {

            $stmt = $this->db->prepare("
                UPDATE {$this->table}

                SET

                    payment_reference=?,
                    status='paid',
                    updated_at=NOW()

                WHERE id=?
            ");

            return $stmt->execute([
                $paymentReference,
                $id
            ]);

        } catch(Throwable $e){

            Logger::write(
                'escrow_paid_error',
                [
                    'message'=>$e->getMessage(),
                    'line'=>$e->getLine()
                ]
            );

            return false;

        }

    }

    /**
     * Release Funds
     */
    public function release(int $id): bool
    {
        try {

            $stmt = $this->db->prepare("
                UPDATE {$this->table}

                SET

                    status='completed',

                    released_at=NOW(),

                    updated_at=NOW()

                WHERE id=?
            ");

            return $stmt->execute([$id]);

        } catch(Throwable $e){

            Logger::write(
                'escrow_release_error',
                [
                    'message'=>$e->getMessage(),
                    'line'=>$e->getLine()
                ]
            );

            return false;

        }

    }

    /**
     * Cancel
     */
    public function cancel(int $id): bool
    {
        try {

            $stmt = $this->db->prepare("
                UPDATE {$this->table}

                SET

                    status='cancelled',

                    cancelled_at=NOW(),

                    updated_at=NOW()

                WHERE id=?
            ");

            return $stmt->execute([$id]);

        } catch(Throwable $e){

            Logger::write(
                'escrow_cancel_error',
                [
                    'message'=>$e->getMessage(),
                    'line'=>$e->getLine()
                ]
            );

            return false;

        }

    }
    
    /**
 * Update Escrow
 */
public function update(
    int $id,
    array $data
): bool
{

    try {

        Logger::write(
            'escrow_model',
            [
                'step'=>'UPDATE_START',
                'id'=>$id,
                'data'=>$data
            ]
        );


        $db = Database::getInstance()->connection();


        /*
        | Prevent empty status corruption
        */

        if(
            array_key_exists('status',$data)
            &&
            trim((string)$data['status']) === ''
        ){

            Logger::write(
                'escrow_model_error',
                [
                    'step'=>'EMPTY_STATUS_BLOCKED',
                    'id'=>$id,
                    'data'=>$data
                ]
            );

            unset($data['status']);

        }



        if(empty($data)){

            return false;

        }



        $fields=[];


        foreach($data as $key=>$value)
        {

            $fields[] = "{$key} = :{$key}";

        }


        $fields[]="updated_at = NOW()";


        $sql="
            UPDATE {$this->table}
            SET
            ".implode(',',$fields)."
            WHERE id=:id
        ";


        $stmt=$db->prepare($sql);


        $data['id']=$id;


        $result=$stmt->execute($data);



        Logger::write(
            'escrow_model',
            [
                'step'=>'UPDATE_RESULT',
                'success'=>$result
            ]
        );


        return $result;


    }
    catch(Throwable $e)
    {

        Logger::write(
            'escrow_model_error',
            [
                'step'=>'UPDATE_ERROR',
                'message'=>$e->getMessage(),
                'line'=>$e->getLine()
            ]
        );


        return false;

    }

}

/**
 * Buyer Confirmed Item
 */
public function buyerConfirm(
    int $id
): bool {

    return $this->update(
        $id,
        [
            'status' => 'buyer_confirmed',
            'buyer_confirmed_at' => date('Y-m-d H:i:s')
        ]
    );

}

/**
 * Seller Confirmed
 */
public function sellerConfirm(
    int $id
): bool {

    return $this->update(
        $id,
        [
            'seller_confirmed_at' => date('Y-m-d H:i:s')
        ]
    );

}

/**
 * Buyer Escrows
 */
public function byBuyer(
    int $buyerId
): array {

    $stmt = $this->db->prepare("
        SELECT *
        FROM {$this->table}
        WHERE buyer_id=?
        ORDER BY id DESC
    ");

    $stmt->execute([$buyerId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);

}

/**
 * Seller Escrows
 */
public function bySeller(
    int $sellerId
): array {

    $stmt = $this->db->prepare("
        SELECT *
        FROM {$this->table}
        WHERE seller_id=?
        ORDER BY id DESC
    ");

    $stmt->execute([$sellerId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);

}

/**
 * Escrow Statistics
 */
public function statistics(
    int $userId
): array {

    $stmt = $this->db->prepare("
        SELECT

            COUNT(*) total,

            SUM(amount) amount

        FROM {$this->table}

        WHERE buyer_id=?

        OR seller_id=?
    ");

    $stmt->execute([
        $userId,
        $userId
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total' => 0,
        'amount' => 0
    ];

}



}