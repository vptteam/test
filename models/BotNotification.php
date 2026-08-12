<?php

declare(strict_types=1);

namespace Models;

use Core\Database;
use Core\Logger;
use PDO;
use Throwable;

class BotNotification
{
    protected PDO $db;

    protected string $table = 'bot_notifications';

    public function __construct()
    {
        $this->db = Database::getInstance()->connection();
    }

    /**
     * --------------------------------------------------------------------------
     * Queue Notification
     * --------------------------------------------------------------------------
     */
    public function create(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $reference = null
    ): bool {

        try {

            Logger::write(
                'bot_notification',
                [
                    'step'      => 'CREATE_START',
                    'user_id'   => $userId,
                    'type'      => $type,
                    'reference' => $reference
                ]
            );

            if (
                $reference !== null
                &&
                $this->exists(
                    $userId,
                    $type,
                    $reference
                )
            ) {

                Logger::write(
                    'bot_notification',
                    [
                        'step'      => 'DUPLICATE_SKIPPED',
                        'user_id'   => $userId,
                        'type'      => $type,
                        'reference' => $reference
                    ]
                );

                return true;

            }

            $stmt = $this->db->prepare(
                "
                INSERT INTO {$this->table}
                (
                    user_id,
                    type,
                    title,
                    message,
                    reference,
                    status,
                    created_at,
                    updated_at
                )

                VALUES
                (
                    :user_id,
                    :type,
                    :title,
                    :message,
                    :reference,
                    'pending',
                    NOW(),
                    NOW()
                )
                "
            );

            $result = $stmt->execute([

                'user_id'   => $userId,
                'type'      => $type,
                'title'     => $title,
                'message'   => $message,
                'reference' => $reference

            ]);

            Logger::write(
                'bot_notification',
                [
                    'step'      => 'CREATE_RESULT',
                    'success'   => $result,
                    'user_id'   => $userId,
                    'type'      => $type,
                    'reference' => $reference
                ]
            );

            return $result;

        }

        catch (Throwable $e) {

            Logger::write(
                'bot_notification_error',
                [
                    'step'      => 'CREATE_FAILED',
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                    'user_id'   => $userId,
                    'type'      => $type,
                    'reference' => $reference
                ]
            );

            return false;

        }

    }

    /**
     * --------------------------------------------------------------------------
     * Oldest Pending Notification
     * --------------------------------------------------------------------------
     */
    public function unread(
        int $userId
    ): ?array {

        $stmt = $this->db->prepare(
            "
            SELECT *

            FROM {$this->table}

            WHERE
                user_id = ?
                AND status='pending'

            ORDER BY id ASC

            LIMIT 1
            "
        );

        $stmt->execute([$userId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    }

    /**
     * --------------------------------------------------------------------------
     * All Pending Notifications
     * --------------------------------------------------------------------------
     */
    public function unreadAll(
        int $userId
    ): array {

        $stmt = $this->db->prepare(
            "
            SELECT *

            FROM {$this->table}

            WHERE
                user_id = ?
                AND status='pending'

            ORDER BY id ASC
            "
        );

        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    }

    /**
     * --------------------------------------------------------------------------
     * Mark One Notification Sent
     * --------------------------------------------------------------------------
     */
    public function markRead(
        int $id
    ): bool {

        $stmt = $this->db->prepare(
            "
            UPDATE {$this->table}

            SET
                status='sent',
                updated_at=NOW()

            WHERE id=?
            "
        );

        return $stmt->execute([$id]);

    }

    /**
     * --------------------------------------------------------------------------
     * Mark All Notifications Sent
     * --------------------------------------------------------------------------
     */
    public function markAllRead(
        int $userId
    ): bool {

        $stmt = $this->db->prepare(
            "
            UPDATE {$this->table}

            SET
                status='sent',
                updated_at=NOW()

            WHERE
                user_id=?
                AND status='pending'
            "
        );

        return $stmt->execute([$userId]);

    }

    /**
     * --------------------------------------------------------------------------
     * Notification Exists?
     * --------------------------------------------------------------------------
     */
    public function exists(
        int $userId,
        string $type,
        string $reference
    ): bool {

        $stmt = $this->db->prepare(
            "
            SELECT id

            FROM {$this->table}

            WHERE
                user_id=?
                AND type=?
                AND reference=?

            LIMIT 1
            "
        );

        $stmt->execute([
            $userId,
            $type,
            $reference
        ]);

        return (bool)$stmt->fetchColumn();

    }

    /**
     * --------------------------------------------------------------------------
     * Pending Count
     * --------------------------------------------------------------------------
     */
    public function pendingCount(
        int $userId
    ): int {

        $stmt = $this->db->prepare(
            "
            SELECT COUNT(*)

            FROM {$this->table}

            WHERE
                user_id=?
                AND status='pending'
            "
        );

        $stmt->execute([$userId]);

        return (int)$stmt->fetchColumn();

    }

    /**
     * --------------------------------------------------------------------------
     * Delete Notification
     * --------------------------------------------------------------------------
     */
    public function delete(
        int $id
    ): bool {

        $stmt = $this->db->prepare(
            "
            DELETE

            FROM {$this->table}

            WHERE id=?
            "
        );

        return $stmt->execute([$id]);

    }

    /**
     * --------------------------------------------------------------------------
     * Delete All Notifications
     * --------------------------------------------------------------------------
     */
    public function deleteAll(
        int $userId
    ): bool {

        $stmt = $this->db->prepare(
            "
            DELETE

            FROM {$this->table}

            WHERE user_id=?
            "
        );

        return $stmt->execute([$userId]);

    }

}