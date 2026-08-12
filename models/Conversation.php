<?php

declare(strict_types=1);

namespace Models;

use Core\Database;
use Core\Logger;

class Conversation
{

    protected Database $db;


    public function __construct()
    {

        Logger::write(
            'conversation_debug',
            [
                'step'=>'constructor_start'
            ]
        );


        $this->db = Database::getInstance();


        Logger::write(
            'conversation_debug',
            [
                'step'=>'constructor_complete'
            ]
        );

    }



    /**
     * Get active conversation
     */
    public function active(
        int $userId
    ): ?array {


        Logger::write(
            'conversation_debug',
            [
                'step'=>'active_start',
                'user_id'=>$userId
            ]
        );


        $query = "
            SELECT *
            FROM conversations
            WHERE user_id=?
            AND status='active'
            ORDER BY id DESC
            LIMIT 1
        ";



        Logger::write(
            'conversation_debug',
            [
                'step'=>'active_query',
                'query'=>$query
            ]
        );



        $row = $this->db
            ->query(

                $query,

                [
                    $userId
                ]

            )
            ->fetch();



        Logger::write(
            'conversation_debug',
            [
                'step'=>'active_result',
                'conversation'=>$row
            ]
        );



        return $row ?: null;

    }





    /**
     * Start conversation
     */
    public function start(

        int $userId,

        string $module,

        string $flow,

        string $step

    ): int {



        Logger::write(
            'conversation_debug',
            [
                'step'=>'start_conversation',
                'user_id'=>$userId,
                'module'=>$module,
                'flow'=>$flow,
                'step_name'=>$step
            ]
        );




        $close = $this->db->query(

            "
            UPDATE conversations
            SET status='completed'
            WHERE user_id=?
            AND status='active'
            ",

            [
                $userId
            ]

        );



        Logger::write(
            'conversation_debug',
            [
                'step'=>'old_conversations_closed'
            ]
        );





        $id = $this->db->insert(

            "
            INSERT INTO conversations
            (
                user_id,
                module,
                flow,
                step,
                data,
                status
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
            ",

            [

                $userId,

                $module,

                $flow,

                $step,

                json_encode(
                    [],
                    JSON_UNESCAPED_UNICODE
                ),

                'active'

            ]

        );



        Logger::write(
            'conversation_debug',
            [
                'step'=>'conversation_created',
                'id'=>$id
            ]
        );



        return (int)$id;


    }





    /**
     * Update workflow step
     */
    public function updateStep(

        int $conversationId,

        string $step

    ): void {


        Logger::write(
            'conversation_debug',
            [
                'step'=>'update_step_start',
                'conversation_id'=>$conversationId,
                'new_step'=>$step
            ]
        );



        $this->db->query(

            "
            UPDATE conversations
            SET step=?
            WHERE id=?
            ",

            [

                $step,

                $conversationId

            ]

        );



        Logger::write(
            'conversation_debug',
            [
                'step'=>'update_step_complete'
            ]
        );


    }





    /**
     * Save conversation data
     */
    public function saveData(

        int $conversationId,

        array $data

    ): void {


        Logger::write(
            'conversation_debug',
            [
                'step'=>'save_data_start',
                'conversation_id'=>$conversationId,
                'incoming_data'=>$data
            ]
        );



        $json = json_encode(

            $data,

            JSON_UNESCAPED_UNICODE

        );



        Logger::write(
            'conversation_debug',
            [
                'step'=>'json_created',
                'json'=>$json
            ]
        );





        $result = $this->db->query(

            "
            UPDATE conversations
            SET data=?
            WHERE id=?
            ",

            [

                $json,

                $conversationId

            ]

        );



        Logger::write(
            'conversation_debug',
            [
                'step'=>'database_update_complete',
                'conversation_id'=>$conversationId
            ]
        );






        /*
        |--------------------------------------------------------------------------
        | Verify immediately
        |--------------------------------------------------------------------------
        */


        $verify = $this->db
            ->query(

                "
                SELECT data
                FROM conversations
                WHERE id=?
                ",

                [

                    $conversationId

                ]

            )
            ->fetch();



        Logger::write(
            'conversation_debug',
            [
                'step'=>'save_verify',
                'database_value'=>$verify
            ]
        );


    }


    /**
     * Find conversation by ID
     */
    public function find(
        int $conversationId
    ): ?array {


        Logger::write(
            'conversation_debug',
            [
                'step'=>'find_start',
                'conversation_id'=>$conversationId
            ]
        );



        $row = $this->db
            ->query(

                "
                SELECT *
                FROM conversations
                WHERE id=?
                LIMIT 1
                ",

                [

                    $conversationId

                ]

            )
            ->fetch();



        Logger::write(
            'conversation_debug',
            [
                'step'=>'find_result',
                'conversation'=>$row
            ]
        );



        return $row ?: null;


    }


    /**
     * Finish
     */
    public function finish(

        int $conversationId

    ): void {


        Logger::write(
            'conversation_debug',
            [
                'step'=>'finish_start',
                'conversation_id'=>$conversationId
            ]
        );



        $this->db->query(

            "
            UPDATE conversations
            SET status='completed'
            WHERE id=?
            ",

            [

                $conversationId

            ]

        );



        Logger::write(
            'conversation_debug',
            [
                'step'=>'finish_complete'
            ]
        );


    }





    /**
     * Cancel
     */
    public function cancel(

        int $userId

    ): void {


        Logger::write(
            'conversation_debug',
            [
                'step'=>'cancel_start',
                'user_id'=>$userId
            ]
        );



        try {


            $this->db->query(

                "
                UPDATE conversations
                SET status='cancelled'
                WHERE user_id=?
                AND status='active'
                ",

                [

                    $userId

                ]

            );



            Logger::write(
                'conversation_debug',
                [
                    'step'=>'cancel_complete'
                ]
            );



        } catch(\Throwable $e) {


            Logger::write(
                'conversation_error',
                [
                    'message'=>$e->getMessage(),
                    'file'=>$e->getFile(),
                    'line'=>$e->getLine()
                ]
            );


            throw $e;

        }


    }


}