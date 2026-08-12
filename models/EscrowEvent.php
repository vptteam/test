<?php

declare(strict_types=1);

namespace Models;

use Core\Database;
use Core\Logger;
use PDO;
use Throwable;

class EscrowEvent
{
    protected string $table = 'escrow_events';

    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->connection();
    }

    /**
     * Create Event
     */
    public function create(array $data): int|false
    {
        try {

            $stmt = $this->db->prepare("
                INSERT INTO {$this->table}
                (
                    escrow_id,
                    user_id,
                    event,
                    notes
                )

                VALUES
                (
                    :escrow_id,
                    :user_id,
                    :event,
                    :notes
                )
            ");

            $stmt->execute([

                'escrow_id' => $data['escrow_id'],

                'user_id'   => $data['user_id'] ?? null,

                'event'     => $data['event'],

                'notes'     => $data['notes'] ?? null

            ]);

            return (int)$this->db->lastInsertId();

        } catch (Throwable $e) {

            Logger::write(
                'escrow_event_create_error',
                [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine()
                ]
            );

            return false;

        }
    }

    /**
     * Get all events
     */
    public function all(int $escrowId): array
    {
        $stmt = $this->db->prepare("
            SELECT *

            FROM {$this->table}

            WHERE escrow_id=?

            ORDER BY id ASC
        ");

        $stmt->execute([
            $escrowId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Latest event
     */
    public function latest(int $escrowId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT *

            FROM {$this->table}

            WHERE escrow_id=?

            ORDER BY id DESC

            LIMIT 1
        ");

        $stmt->execute([
            $escrowId
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Count events
     */
    public function count(int $escrowId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)

            FROM {$this->table}

            WHERE escrow_id=?
        ");

        $stmt->execute([
            $escrowId
        ]);

        return (int)$stmt->fetchColumn();
    }
}