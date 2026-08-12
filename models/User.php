<?php

declare(strict_types=1);

namespace Models;

use Core\Database;
use PDO;
use Services\SendamIdGenerator;


class User
{

    protected Database $db;


    public function __construct()
    {
        $this->db = Database::getInstance();
    }





    /**
     * Find user by internal ID
     */
    public function find(int $id): ?array
    {

        $stmt = $this->db
            ->connection()
            ->prepare(
                "
                SELECT *
                FROM users
                WHERE id=?
                LIMIT 1
                "
            );


        $stmt->execute([
            $id
        ]);


        $user = $stmt->fetch(PDO::FETCH_ASSOC);


        return $user ?: null;

    }





    /**
     * Find user by Telegram ID
     */
    public function findByTelegramId(
        int $telegramId
    ): ?array
    {

        $stmt = $this->db
            ->connection()
            ->prepare(
                "
                SELECT *
                FROM users
                WHERE telegram_id=?
                LIMIT 1
                "
            );


        $stmt->execute([
            $telegramId
        ]);


        $user = $stmt->fetch(PDO::FETCH_ASSOC);


        return $user ?: null;

    }







    /**
     * Create or get Telegram user
     */
    public function findOrCreateTelegramUser(

        int $telegramId,

        ?string $phone=null,

        ?string $name=null

    ): array
    {


        $user = $this->findByTelegramId(
            $telegramId
        );


        if($user){

            return $user;

        }






        $sendamId =

    (new SendamIdGenerator())

        ->generate();



$id = $this->db->insert(

    "
    INSERT INTO users
    (
        sendam_id,
        telegram_id,
        platform,
        platform_id,
        phone,
        name,
        created_at,
        updated_at
    )

    VALUES
    (
        ?,
        ?,
        'telegram',
        ?,
        ?,
        ?,
        NOW(),
        NOW()
    )
    ",

    [

        $sendamId,

        $telegramId,

        (string)$telegramId,

        $phone,

        $name

    ]

);




        return $this->find(
            (int)$id
        );

    }









    /**
     * Alias
     */
    public function findOrCreate(

        int $telegramId,

        array $data=[]

    ): array
    {


        return $this->findOrCreateTelegramUser(

            $telegramId,

            $data['phone'] ?? null,

            $data['name'] ?? null

        );


    }









    /**
     * Find by phone
     */
    public function findByIdentifier(

        string $identifier

    ): ?array
    {


        $stmt = $this->db
            ->connection()
            ->prepare(

                "
                SELECT *
                FROM users
                WHERE phone=?
                LIMIT 1
                "

            );


        $stmt->execute([

            $identifier

        ]);



        $user = $stmt->fetch(
            PDO::FETCH_ASSOC
        );


        return $user ?: null;


    }









   /**
 * Create normal user
 */
public function create(
    string $phone
): int
{

    $sendamId =

        (new SendamIdGenerator())

            ->generate();


    return $this->db->insert(

        "
        INSERT INTO users
        (
            sendam_id,
            phone,
            current_state,
            created_at,
            updated_at
        )

        VALUES
        (
            ?,
            ?,
            'welcome',
            NOW(),
            NOW()
        )
        ",

        [

            $sendamId,

            $phone

        ]

    );

}






    /**
     * Update user state
     */
    public function updateState(

        int $id,

        string $state

    ): void
    {


        $this->db->query(

            "
            UPDATE users
            SET current_state=?
            WHERE id=?
            ",

            [

                $state,

                $id

            ]

        );


    }
    
    /**
 * Find or create user by platform
 */
public function findOrCreatePlatformUser(
    string $platform,
    string $platformId,
    ?string $phone = null,
    ?string $name = null
): array
{

    $stmt = $this->db
        ->connection()
        ->prepare(
            "
            SELECT *
            FROM users
            WHERE platform = ?
            AND platform_id = ?
            LIMIT 1
            "
        );


    $stmt->execute([
        $platform,
        $platformId
    ]);


    $user = $stmt->fetch(PDO::FETCH_ASSOC);


    if($user){

        return $user;

    }



    /*
|--------------------------------------------------------------------------
| Generate Sendam ID
|--------------------------------------------------------------------------
*/

$sendamId =

    (new SendamIdGenerator())

        ->generate();



$id = $this->db->insert(

    "
    INSERT INTO users
    (
        sendam_id,
        platform,
        platform_id,
        phone,
        name,
        created_at,
        updated_at
    )

    VALUES
    (
        ?,
        ?,
        ?,
        ?,
        ?,
        NOW(),
        NOW()
    )
    ",

    [

        $sendamId,

        $platform,

        $platformId,

        $phone,

        $name

    ]

);

    return $this->find(
        (int)$id
    );

}



}