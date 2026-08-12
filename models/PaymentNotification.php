<?php

declare(strict_types=1);

namespace Models;

use Core\Database;
use PDO;

class PaymentNotification
{
    protected PDO $db;

    protected string $table = 'payment_notifications';

    public function __construct()
    {
        $this->db = Database::getInstance()->connection();
    }

    /**
     * ----------------------------------------------------------
     * Create notification
     * ----------------------------------------------------------
     */
    public function create(
        int $userId,
        string $reference
    ): bool {

        $stmt = $this->db->prepare(

            "
            INSERT INTO {$this->table}
            (
                user_id,
                reference,
                message_sent,
                created_at
            )
            VALUES
            (
                :user_id,
                :reference,
                0,
                NOW()
            )
            "

        );

        return $stmt->execute([

            'user_id'   => $userId,
            'reference' => $reference

        ]);

    }

    /**
     * ----------------------------------------------------------
     * Check if notification already exists
     * ----------------------------------------------------------
     */
    public function exists(
        int $userId,
        string $reference
    ): bool {

        $stmt = $this->db->prepare(

            "
            SELECT id

            FROM {$this->table}

            WHERE user_id = ?

            AND reference = ?

            LIMIT 1
            "

        );

        $stmt->execute([

            $userId,
            $reference

        ]);

        return (bool)$stmt->fetchColumn();

    }

    /**
     * ----------------------------------------------------------
     * Get first pending notification
     * ----------------------------------------------------------
     */
    public function pendingForUser(
        int $userId
    ): ?array {

        $stmt = $this->db->prepare(

            "
            SELECT *

            FROM {$this->table}

            WHERE user_id = ?

            AND message_sent = 0

            ORDER BY id ASC

            LIMIT 1
            "

        );

        $stmt->execute([$userId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;

    }

    /**
     * ----------------------------------------------------------
     * Mark notification as sent
     * ----------------------------------------------------------
     */
    public function markSent(
        int $id
    ): bool {

        $stmt = $this->db->prepare(

            "
            UPDATE {$this->table}

            SET
                message_sent = 1

            WHERE id = ?
            "

        );

        return $stmt->execute([$id]);

    }
}