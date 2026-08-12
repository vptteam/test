<?php

declare(strict_types=1);

namespace Models;

use Core\Database;
use Throwable;



class AdvertUpgradePayment
{


    protected $db;



    public function __construct()
    {

        $this->db =
        Database::getInstance()->connection();

    }








    /**
     * Create payment record
     */
    public function create(array $data): bool
    {


        $sql = "

        INSERT INTO advert_upgrade_payments

        (
            user_id,
            package_id,
            reference,
            amount,
            status
        )

        VALUES

        (
            :user_id,
            :package_id,
            :reference,
            :amount,
            :status
        )

        ";



        return $this->db
            ->prepare($sql)
            ->execute([


                'user_id'=>
                    $data['user_id'],


                'package_id'=>
                    $data['package_id'],


                'reference'=>
                    $data['reference'],


                'amount'=>
                    $data['amount'],


                'status'=>
                    $data['status']
                    ??
                    'pending'


            ]);


    }









    /**
     * Find payment by reference
     */
    public function findByReference(
        string $reference
    ): ?array {


        $stmt =
            $this->db->prepare(

            "

            SELECT *

            FROM advert_upgrade_payments

            WHERE reference=:reference

            LIMIT 1

            "

        );


        $stmt->execute([

            'reference'=>$reference

        ]);



        $row =
            $stmt->fetch();



        return $row ?: null;


    }









    /**
     * Find by ID
     */
    public function find(
        int $id
    ): ?array {


        $stmt =
            $this->db->prepare(

            "

            SELECT *

            FROM advert_upgrade_payments

            WHERE id=:id

            LIMIT 1

            "

        );


        $stmt->execute([

            'id'=>$id

        ]);



        $row =
            $stmt->fetch();



        return $row ?: null;


    }









    /**
     * Get pending payment
     */
    public function pendingForUser(
        int $userId
    ): ?array {


        $stmt =
            $this->db->prepare(

            "

            SELECT *

            FROM advert_upgrade_payments

            WHERE user_id=:user_id

            AND status='pending'

            ORDER BY id DESC

            LIMIT 1

            "

        );


        $stmt->execute([

            'user_id'=>$userId

        ]);



        $row =
            $stmt->fetch();



        return $row ?: null;


    }









    /**
     * Save Paystack payment URL
     */
    public function savePaymentUrl(

        string $reference,

        string $url

    ): bool {


        $stmt =
            $this->db->prepare(

            "

            UPDATE advert_upgrade_payments

            SET

            payment_url=:url

            WHERE reference=:reference

            "

        );



        return $stmt->execute([


            'url'=>$url,


            'reference'=>$reference


        ]);


    }









    /**
     * Mark payment as paid
     */
    public function markPaid(

        int $id,

        array $transaction = []

    ): bool {


        $stmt =
            $this->db->prepare(

            "

            UPDATE advert_upgrade_payments

            SET

            status='paid',

            paid_at=NOW(),

            transaction_id=:transaction_id,

            gateway_response=:gateway_response

            WHERE id=:id

            AND status!='paid'

            "

        );



        return $stmt->execute([



            'id'=>$id,



            'transaction_id'=>

                $transaction['id']
                ??
                null,



            'gateway_response'=>

                json_encode(
                    $transaction
                )


        ]);


    }









    /**
     * Backward compatibility
     *
     * Old code can still call markSuccess()
     */
    public function markSuccess(

        string $reference

    ): bool {


        $payment =
            $this->findByReference(

                $reference

            );



        if(!$payment){

            return false;

        }



        return $this->markPaid(

            (int)$payment['id']

        );


    }









    /**
     * Mark failed payment
     */
    public function markFailed(

        string $reference

    ): bool {


        $stmt =
            $this->db->prepare(

            "

            UPDATE advert_upgrade_payments

            SET

            status='failed'

            WHERE reference=:reference

            "

        );



        return $stmt->execute([



            'reference'=>$reference



        ]);


    }









}