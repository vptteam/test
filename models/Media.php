<?php

declare(strict_types=1);

namespace Models;

use Core\Database;
use Core\Logger;
use Throwable;


class Media
{

    protected Database $db;



    public function __construct()
    {

        Logger::write(

            'media_model_debug',

            [

                'step'=>'constructor_start'

            ]

        );


        $this->db = Database::getInstance();



        Logger::write(

            'media_model_debug',

            [

                'step'=>'constructor_complete'

            ]

        );

    }






    /**
     * Save Media
     */
    public function create(
        array $data
    ): int {


        Logger::write(

            'media_model_debug',

            [

                'step'=>'create_start',

                'data'=>$data

            ]

        );



        try {


            $id = $this->db->insert(

                "INSERT INTO media
                (
                    module,
                    record_id,
                    platform,
                    media_type,
                    media_id,
                    filename,
                    filepath,
                    mime_type,
                    file_size,
                    width,
                    height
                )

                VALUES
                (
                    ?,?,?,?,?,?,?,?,?,?,?
                )",

                [

                    $data['module'] ?? '',

                    $data['record_id'] ?? 0,

                    $data['platform'] ?? '',

                    $data['media_type'] ?? 'image',

                    $data['media_id'] ?? null,

                    $data['filename'] ?? '',

                    $data['filepath'] ?? '',

                    $data['mime_type'] ?? '',

                    $data['file_size'] ?? 0,

                    $data['width'] ?? 0,

                    $data['height'] ?? 0

                ]

            );



            Logger::write(

                'media_model_debug',

                [

                    'step'=>'create_complete',

                    'id'=>$id

                ]

            );



            return $id;



        } catch(Throwable $e){


            Logger::write(

                'media_model_error',

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
     * Get all media
     */
    public function all(
        string $module,
        int $recordId
    ): array {


        Logger::write(

            'media_model_debug',

            [

                'step'=>'all_start',

                'module'=>$module,

                'record_id'=>$recordId

            ]

        );



        $result = $this->db

            ->query(

                "SELECT *

                 FROM media

                 WHERE module=?

                 AND record_id=?

                 ORDER BY id ASC",

                [

                    $module,

                    $recordId

                ]

            )

            ->fetchAll();



        Logger::write(

            'media_model_debug',

            [

                'step'=>'all_complete',

                'count'=>count($result)

            ]

        );



        return $result;


    }









    /**
     * Get only images
     */
    public function images(
        string $module,
        int $recordId
    ): array {


        Logger::write(

            'media_model_debug',

            [

                'step'=>'images_start',

                'module'=>$module,

                'record_id'=>$recordId

            ]

        );



        $images = $this->db

            ->query(

                "SELECT *

                 FROM media

                 WHERE module=?

                 AND record_id=?

                 AND media_type='image'

                 ORDER BY id ASC",

                [

                    $module,

                    $recordId

                ]

            )

            ->fetchAll();





        Logger::write(

            'media_model_debug',

            [

                'step'=>'images_complete',

                'count'=>count($images),

                'images'=>$images

            ]

        );



        return $images;


    }









    /**
     * Get first image
     */
    public function primary(
        string $module,
        int $recordId
    ): ?array {


        Logger::write(

            'media_model_debug',

            [

                'step'=>'primary_start',

                'module'=>$module,

                'record_id'=>$recordId

            ]

        );



        $media = $this->db

            ->query(

                "SELECT *

                 FROM media

                 WHERE module=?

                 AND record_id=?

                 AND media_type='image'

                 ORDER BY id ASC

                 LIMIT 1",

                [

                    $module,

                    $recordId

                ]

            )

            ->fetch();



        Logger::write(

            'media_model_debug',

            [

                'step'=>'primary_complete',

                'media'=>$media

            ]

        );



        return $media ?: null;


    }









    /**
     * Count media
     */
    public function count(
        string $module,
        int $recordId
    ): int {


        $result = $this->db

            ->query(

                "SELECT COUNT(*) as total

                 FROM media

                 WHERE module=?

                 AND record_id=?",

                [

                    $module,

                    $recordId

                ]

            )

            ->fetch();



        $count = (int)(
            $result['total'] ?? 0
        );



        Logger::write(

            'media_model_debug',

            [

                'step'=>'count_complete',

                'module'=>$module,

                'record_id'=>$recordId,

                'count'=>$count

            ]

        );



        return $count;


    }









    /**
     * Find media by ID
     */
    public function find(
        int $id
    ): ?array {


        Logger::write(

            'media_model_debug',

            [

                'step'=>'find_start',

                'id'=>$id

            ]

        );



        $media = $this->db

            ->query(

                "SELECT *

                 FROM media

                 WHERE id=?

                 LIMIT 1",

                [

                    $id

                ]

            )

            ->fetch();



        return $media ?: null;


    }









    /**
     * Delete media
     */
    public function delete(
        string $module,
        int $recordId
    ): void {


        Logger::write(

            'media_model_debug',

            [

                'step'=>'delete_start',

                'module'=>$module,

                'record_id'=>$recordId

            ]

        );



        $this->db->query(

            "DELETE FROM media

             WHERE module=?

             AND record_id=?",

            [

                $module,

                $recordId

            ]

        );



        Logger::write(

            'media_model_debug',

            [

                'step'=>'delete_complete'

            ]

        );


    }


}