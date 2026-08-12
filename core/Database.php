<?php

declare(strict_types=1);

namespace Core;

use PDO;
use PDOException;
use Core\Logger;

class Database
{
    private static ?Database $instance = null;

    private PDO $connection;

    private function __construct()
{
    try {


        Logger::write(
            'database_debug',
            [
                'step'=>'before_pdo_connect'
            ]
        );


        $this->connection = new PDO(

            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",

            DB_USER,

            DB_PASS,

            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                PDO::ATTR_EMULATE_PREPARES => false,
            ]

        );


        Logger::write(
            'database_debug',
            [
                'step'=>'after_pdo_connect'
            ]
        );


    } catch (PDOException $e) {


        Logger::write(
            'database_debug_error',
            [
                'message'=>$e->getMessage()
            ]
        );


        throw $e;


    }
}

    public static function getInstance(): Database
    {

        if (self::$instance === null) {

            self::$instance = new Database();

        }

        return self::$instance;

    }

    public function connection(): PDO
    {

        return $this->connection;

    }

    public function query(string $sql, array $params = [])
    {

        $stmt = $this->connection->prepare($sql);

        $stmt->execute($params);

        return $stmt;

    }

    public function insert(string $sql, array $params = []): int
    {

        $stmt = $this->connection->prepare($sql);

        $stmt->execute($params);

        return (int)$this->connection->lastInsertId();

    }

}