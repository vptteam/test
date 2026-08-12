<?php

declare(strict_types=1);

namespace Models;

use Core\Database;
use Core\Logger;
use Throwable;


class Listing
{

    protected Database $db;



    public function __construct()
    {

        Logger::write(
            'listing_debug',
            [
                'step'=>'constructor_start'
            ]
        );


        $this->db = Database::getInstance();



        Logger::write(
            'listing_debug',
            [
                'step'=>'constructor_complete'
            ]
        );

    }







    /**
     * Create Listing
     */
    public function create(
        int $userId,
        array $data
    ): int {


        Logger::write(
            'listing_debug',
            [
                'step'=>'create_start',
                'user_id'=>$userId,
                'data'=>$data
            ]
        );



        try {


            $id = $this->db->insert(

                "INSERT INTO listings
                (
                    user_id,
                    title,
                    price,
                    location,
                    description,
                    status
                )

                VALUES(?,?,?,?,?,?)",

                [

                    $userId,

                    $data['title'] ?? '',

                    $data['price'] ?? 0,

                    $data['location'] ?? '',

                    $data['description'] ?? '',

                    'pending'

                ]

            );



            Logger::write(
                'listing_debug',
                [
                    'step'=>'create_complete',
                    'listing_id'=>$id
                ]
            );



            return $id;



        } catch(Throwable $e){


            Logger::write(
                'listing_error',
                [
                    'step'=>'create_failed',
                    'message'=>$e->getMessage(),
                    'line'=>$e->getLine(),
                    'data'=>$data
                ]
            );


            throw $e;

        }


    }



/**
 * Generate Listing Reference
 */
public function generateReference(
    int $listingId
): string {

    return 'SDM-' . str_pad(
        (string)$listingId,
        6,
        '0',
        STR_PAD_LEFT
    );

}



/**
 * Save Listing Reference
 */
public function updateReference(
    int $listingId,
    string $reference
): void {

    Logger::write(
        'listing_debug',
        [
            'step'=>'update_reference',
            'listing_id'=>$listingId,
            'reference'=>$reference
        ]
    );

    $this->db->query(

        "UPDATE listings
         SET reference=?
         WHERE id=?",

        [

            $reference,

            $listingId

        ]

    );

}



/**
 * Find Listing By Reference
 */
public function findByReference(
    string $reference
): ?array {

    Logger::write(
        'listing_debug',
        [
            'step'=>'find_by_reference',
            'reference'=>$reference
        ]
    );

    $listing = $this->db

        ->query(

            "SELECT *
             FROM listings
             WHERE reference=?
             LIMIT 1",

            [

                $reference

            ]

        )

        ->fetch();

    return $listing ?: null;

}





    /**
     * Find Listing With Media
     */
    public function find(
        int $id
    ): ?array {


        Logger::write(
            'listing_debug',
            [
                'step'=>'find_start',
                'listing_id'=>$id
            ]
        );



        try {


            $listing = $this->db

                ->query(

                    "SELECT *

                     FROM listings

                     WHERE id=?

                     LIMIT 1",

                    [

                        $id

                    ]

                )

                ->fetch();




            Logger::write(
                'listing_debug',
                [
                    'step'=>'listing_query_complete',
                    'listing'=>$listing
                ]
            );




            if(!$listing){


                Logger::write(
                    'listing_debug',
                    [
                        'step'=>'listing_not_found',
                        'id'=>$id
                    ]
                );


                return null;

            }






            /*
            |--------------------------------------------------------------------------
            | Load Listing Photos
            |--------------------------------------------------------------------------
            */


            Logger::write(
                'listing_debug',
                [
                    'step'=>'before_media_load',
                    'listing_id'=>$id
                ]
            );



            $mediaModel = new Media();



            $photos = $mediaModel->images(

                'marketplace',

                $id

            );




            Logger::write(
                'listing_debug',
                [
                    'step'=>'after_media_load',
                    'photo_count'=>count($photos),
                    'photos'=>$photos
                ]
            );





            $listing['photos'] = $photos;





            Logger::write(
                'listing_debug',
                [
                    'step'=>'find_complete',
                    'listing'=>$listing
                ]
            );



            return $listing;



        } catch(Throwable $e){


            Logger::write(
                'listing_error',
                [
                    'step'=>'find_failed',
                    'message'=>$e->getMessage(),
                    'line'=>$e->getLine(),
                    'id'=>$id
                ]
            );


            throw $e;

        }


    }









    /**
     * User Listings
     */
    public function userListings(
        int $userId
    ): array {


        Logger::write(
            'listing_debug',
            [
                'step'=>'userListings_start',
                'user_id'=>$userId
            ]
        );



        $rows = $this->db

            ->query(

                "SELECT *

                 FROM listings

                 WHERE user_id=?

                 ORDER BY id DESC",

                [

                    $userId

                ]

            )

            ->fetchAll();



        Logger::write(
            'listing_debug',
            [
                'step'=>'userListings_complete',
                'count'=>count($rows)
            ]
        );



        return $rows;


    }









    public function approve(
        int $listingId
    ): void {


        Logger::write(
            'listing_debug',
            [
                'step'=>'approve',
                'id'=>$listingId
            ]
        );



        $this->db->query(

            "UPDATE listings

             SET status='approved'

             WHERE id=?",

            [

                $listingId

            ]

        );

    }








    public function reject(
        int $listingId
    ): void {


        Logger::write(
            'listing_debug',
            [
                'step'=>'reject',
                'id'=>$listingId
            ]
        );



        $this->db->query(

            "UPDATE listings

             SET status='rejected'

             WHERE id=?",

            [

                $listingId

            ]

        );

    }









    public function update(
        int $listingId,
        array $data
    ): void {


        Logger::write(
            'listing_debug',
            [
                'step'=>'update_start',
                'id'=>$listingId,
                'data'=>$data
            ]
        );



        $this->db->query(

            "UPDATE listings

             SET

                title=?,

                price=?,

                location=?,

                description=?

             WHERE id=?",

            [

                $data['title'] ?? '',

                $data['price'] ?? 0,

                $data['location'] ?? '',

                $data['description'] ?? '',

                $listingId

            ]

        );



        Logger::write(
            'listing_debug',
            [
                'step'=>'update_complete'
            ]
        );

    }









    public function publish(
        int $listingId
    ): void {


        Logger::write(
            'listing_debug',
            [
                'step'=>'publish_start',
                'id'=>$listingId
            ]
        );



        $this->db->query(

            "UPDATE listings

             SET

                status='published',

                published_at=NOW()

             WHERE id=?",

            [

                $listingId

            ]

        );



        Logger::write(
            'listing_debug',
            [
                'step'=>'publish_complete'
            ]
        );


    }









    public function archive(
        int $listingId
    ): void {


        Logger::write(
            'listing_debug',
            [
                'step'=>'archive',
                'id'=>$listingId
            ]
        );



        $this->db->query(

            "UPDATE listings

             SET status='archived'

             WHERE id=?",

            [

                $listingId

            ]

        );


    }









    public function delete(
        int $listingId
    ): void {


        Logger::write(
            'listing_debug',
            [
                'step'=>'delete',
                'id'=>$listingId
            ]
        );



        $this->db->query(

            "DELETE

             FROM listings

             WHERE id=?",

            [

                $listingId

            ]

        );


    }









    public function latest(
        int $limit = 20
    ): array {


        Logger::write(
            'listing_debug',
            [
                'step'=>'latest',
                'limit'=>$limit
            ]
        );



        $limit = max(1,(int)$limit);



        return $this->db

            ->query(

                "SELECT *

                 FROM listings

                 ORDER BY id DESC

                 LIMIT {$limit}"

            )

            ->fetchAll();


    }









    public function pending(): array
    {


        Logger::write(
            'listing_debug',
            [
                'step'=>'pending'
            ]
        );


        return $this->db

            ->query(

                "SELECT *

                 FROM listings

                 WHERE status='pending'

                 ORDER BY id DESC"

            )

            ->fetchAll();


    }









    public function search(
        string $keyword
    ): array {


        Logger::write(
            'listing_debug',
            [
                'step'=>'search',
                'keyword'=>$keyword
            ]
        );



        return $this->db

            ->query(

                "SELECT *

                 FROM listings

                 WHERE title LIKE ?

                 OR description LIKE ?

                 ORDER BY id DESC",

                [

                    "%{$keyword}%",

                    "%{$keyword}%"

                ]

            )

            ->fetchAll();


    }
  
  

  
  
    public function countByUser(
    int $userId
): int
{
    $stmt = $this->db
        ->connection()
        ->prepare("
            SELECT COUNT(*)
            FROM listings
            WHERE user_id=?
        ");

    $stmt->execute([$userId]);

    return (int)$stmt->fetchColumn();
}



}