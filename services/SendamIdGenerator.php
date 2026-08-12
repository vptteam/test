<?php

declare(strict_types=1);

namespace Services;

use Core\Database;
use PDO;

class SendamIdGenerator
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->connection();
    }

    /**
     * Generate unique Sendam ID
     *
     * Example:
     * SND-48291
     */
    public function generate(): string
    {
        do {

            $id = 'SND-' . random_int(10000, 99999);

        } while ($this->exists($id));

        return $id;
    }

    /**
     * Check whether ID already exists
     */
    protected function exists(
        string $sendamId
    ): bool {

        $stmt = $this->db->prepare(

            "
            SELECT id
            FROM users
            WHERE sendam_id=?
            LIMIT 1
            "

        );

        $stmt->execute([

            $sendamId

        ]);

        return (bool)$stmt->fetchColumn();

    }
}