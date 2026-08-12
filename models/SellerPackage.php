<?php

declare(strict_types=1);

namespace Models;

use Core\Database;
use Core\Logger;
use PDO;
use Throwable;


class SellerPackage
{


    protected string $table = 'seller_packages';





    /**
     * Get all active packages
     */
    public function active(): array
    {

        try {


            $db = Database::getInstance()->connection();



            $stmt = $db->prepare(

                "
                SELECT *
                FROM {$this->table}
                WHERE status = 'active'
                ORDER BY price ASC
                "

            );


            $stmt->execute();



            return $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        }
        catch(Throwable $e){


            Logger::write(

                'seller_package_error',

                [

                    'step'=>'ACTIVE_FAILED',

                    'message'=>$e->getMessage()

                ]

            );


            return [];


        }


    }









    /**
     * Get packages available for upgrade
     *
     * Excludes free plan
     */
    public function upgradePackages(): array
    {


        try {


            
            $db = Database::getInstance()->connection();



            $stmt = $db->prepare(

                "
                SELECT *
                FROM {$this->table}

                WHERE status='active'

                AND slug != 'free'

                ORDER BY price ASC

                "

            );



            $stmt->execute();



            return $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );



        }
        catch(Throwable $e){


            Logger::write(

                'seller_package_error',

                [

                    'step'=>'UPGRADE_PACKAGES_FAILED',

                    'message'=>$e->getMessage()

                ]

            );


            return [];


        }


    }









    /**
     * Find package by ID
     */
    public function find(
        int $id
    ): ?array {


        try {


            $db = Database::getInstance()->connection();



            $stmt = $db->prepare(

                "
                SELECT *
                FROM {$this->table}

                WHERE id = ?

                LIMIT 1

                "

            );



            $stmt->execute(
                [
                    $id
                ]
            );



            $result =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );



            return $result ?: null;



        }
        catch(Throwable $e){


            Logger::write(

                'seller_package_error',

                [

                    'step'=>'FIND_FAILED',

                    'id'=>$id,

                    'message'=>$e->getMessage()

                ]

            );


            return null;


        }


    }









    /**
     * Find package by slug
     */
    public function findBySlug(
        string $slug
    ): ?array {


        try {


         
            $db = Database::getInstance()->connection();



            $stmt = $db->prepare(

                "
                SELECT *
                FROM {$this->table}

                WHERE slug = ?

                LIMIT 1

                "

            );



            $stmt->execute(
                [
                    $slug
                ]
            );



            $result =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );



            return $result ?: null;



        }
        catch(Throwable $e){


            Logger::write(

                'seller_package_error',

                [

                    'step'=>'SLUG_FIND_FAILED',

                    'slug'=>$slug,

                    'message'=>$e->getMessage()

                ]

            );


            return null;


        }


    }









    /**
     * Find active package by slug
     *
     * Prevent payment for disabled packages
     */
    public function findActiveBySlug(
        string $slug
    ): ?array {


        try {


           
            $db = Database::getInstance()->connection();



            $stmt = $db->prepare(

                "
                SELECT *

                FROM {$this->table}

                WHERE slug = ?

                AND status='active'

                LIMIT 1

                "

            );



            $stmt->execute(

                [
                    $slug
                ]

            );



            $result =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );



            return $result ?: null;



        }
        catch(Throwable $e){


            Logger::write(

                'seller_package_error',

                [

                    'step'=>'ACTIVE_SLUG_FAILED',

                    'slug'=>$slug,

                    'message'=>$e->getMessage()

                ]

            );


            return null;


        }


    }



protected array $casts = [

    'price' => 'float',

    'duration_days' => 'integer',

];





    /**
     * Check if package exists
     */
    public function exists(
        string $slug
    ): bool {


        return $this->findBySlug($slug) !== null;


    }






}