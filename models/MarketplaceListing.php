<?php

declare(strict_types=1);

namespace Models;

use Core\Database;
use Core\Logger;

class MarketplaceListing
{

    protected Database $db;


    public function __construct()
    {
        Logger::write(
            'marketplace_listing_model',
            [
                'step'=>'constructor_start'
            ]
        );


        $this->db = Database::getInstance();


        Logger::write(
            'marketplace_listing_model',
            [
                'step'=>'constructor_complete'
            ]
        );
    }



    /**
     * Create listing draft
     */
    public function create(
        array $data
    ): int {


        Logger::write(
            'marketplace_listing_model',
            [
                'step'=>'create_start',
                'data'=>$data
            ]
        );



        $id = $this->db->insert(

            "INSERT INTO marketplace_listings
            (
                user_id,
                title,
                category,
                description,
                price,
                location,
                contact,
                photos,
                status
            )
            VALUES(?,?,?,?,?,?,?,?,?)",

            [

                $data['user_id'] ?? null,

                $data['title'] ?? null,

                $data['category'] ?? null,

                $data['description'] ?? null,

                $data['price'] ?? null,

                $data['location'] ?? null,

                $data['contact'] ?? null,


                json_encode(
                    $data['photos'] ?? [],
                    JSON_UNESCAPED_UNICODE
                ),


                'draft'

            ]

        );



        Logger::write(
            'marketplace_listing_model',
            [
                'step'=>'create_complete',
                'listing_id'=>$id
            ]
        );


        return $id;

    }




    /**
     * Find listing
     */
    public function find(
        int $id
    ): ?array {


        Logger::write(
            'marketplace_listing_model',
            [
                'step'=>'find_start',
                'id'=>$id
            ]
        );



        $row = $this->db
            ->query(

                "SELECT *
                 FROM marketplace_listings
                 WHERE id=?",

                [
                    $id
                ]

            )
            ->fetch();



        Logger::write(
            'marketplace_listing_model',
            [
                'step'=>'find_result',
                'listing'=>$row
            ]
        );



        return $row ?: null;

    }





    /**
     * Update listing
     */
    public function update(
        int $id,
        array $data
    ): void {


        Logger::write(
            'marketplace_listing_model',
            [
                'step'=>'update_start',
                'id'=>$id,
                'data'=>$data
            ]
        );



        $fields = [];
        $values = [];



        foreach($data as $key=>$value)
        {

            if($key === 'photos')
            {

                $value = json_encode(
                    $value,
                    JSON_UNESCAPED_UNICODE
                );

            }


            $fields[] = "{$key}=?";

            $values[] = $value;

        }



        $values[] = $id;



        $sql = "

            UPDATE marketplace_listings

            SET ".implode(',', $fields)."

            WHERE id=?

        ";



        $this->db->query(

            $sql,

            $values

        );



        Logger::write(
            'marketplace_listing_model',
            [
                'step'=>'update_complete',
                'id'=>$id
            ]
        );


    }





    /**
     * Mark published
     */
    public function publish(
        int $id,
        string $channel,
        string $messageId
    ): void {


        Logger::write(
            'marketplace_listing_model',
            [
                'step'=>'publish_start',
                'id'=>$id,
                'channel'=>$channel,
                'message_id'=>$messageId
            ]
        );



        $this->db->query(

            "UPDATE marketplace_listings

             SET
             status='published',
             telegram_channel=?,
             telegram_message_id=?

             WHERE id=?",

            [

                $channel,

                $messageId,

                $id

            ]

        );



        Logger::write(
            'marketplace_listing_model',
            [
                'step'=>'publish_complete',
                'id'=>$id
            ]
        );


    }





    /**
     * Get seller listings
     */
    public function sellerListings(
        int $userId
    ): array {


        Logger::write(
            'marketplace_listing_model',
            [
                'step'=>'seller_listings_start',
                'user_id'=>$userId
            ]
        );



        return $this->db
            ->query(

                "SELECT *

                 FROM marketplace_listings

                 WHERE user_id=?

                 ORDER BY id DESC",

                [

                    $userId

                ]

            )
            ->fetchAll();


    }


}