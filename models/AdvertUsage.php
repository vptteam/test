<?php

declare(strict_types=1);

namespace Models;

use Core\Database;
use Core\Logger;
use PDO;
use Throwable;

class AdvertUsage
{
    protected string $table = 'advert_usage';

    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->connection();
    }

    /**
     * Get today's advert usage
     */
    public function today(int $userId): array
    {
        try {

            Logger::write(
                'advert_usage_debug',
                [
                    'step'    => 'TODAY_START',
                    'user_id' => $userId
                ]
            );

            $date = (new \DateTime(
                'now',
                new \DateTimeZone('Africa/Lagos')
            ))->format('Y-m-d');

            $stmt = $this->db->prepare(
                "
                SELECT *
                FROM {$this->table}
                WHERE user_id = ?
                AND usage_date = ?
                LIMIT 1
                "
            );

            $stmt->execute([
                $userId,
                $date
            ]);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {

                Logger::write(
                    'advert_usage_debug',
                    [
                        'step' => 'TODAY_FOUND',
                        'data' => $result
                    ]
                );

                return $result;
            }

            $stmt = $this->db->prepare(
                "
                INSERT INTO {$this->table}
                (
                    user_id,
                    usage_date,
                    adverts_count
                )
                VALUES
                (
                    ?,
                    ?,
                    0
                )
                "
            );

            $stmt->execute([
                $userId,
                $date
            ]);

            Logger::write(
                'advert_usage_debug',
                [
                    'step'    => 'TODAY_CREATED',
                    'user_id' => $userId,
                    'date'    => $date
                ]
            );

            return [
                'user_id'       => $userId,
                'usage_date'    => $date,
                'adverts_count' => 0
            ];

        } catch (Throwable $e) {

            Logger::write(
                'advert_usage_error',
                [
                    'step'    => 'TODAY_FAILED',
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine(),
                    'file'    => $e->getFile()
                ]
            );

            throw $e;
        }
    }

    /**
     * Increment advert count
     */
    public function increment(int $userId): bool
    {
        try {

            Logger::write(
                'advert_usage_debug',
                [
                    'step'    => 'INCREMENT_START',
                    'user_id' => $userId
                ]
            );

            $this->today($userId);

            $date = (new \DateTime(
                'now',
                new \DateTimeZone('Africa/Lagos')
            ))->format('Y-m-d');

            $stmt = $this->db->prepare(
                "
                UPDATE {$this->table}
                SET adverts_count = adverts_count + 1
                WHERE user_id = ?
                AND usage_date = ?
                "
            );

            $success = $stmt->execute([
                $userId,
                $date
            ]);

            Logger::write(
                'advert_usage_debug',
                [
                    'step'    => 'INCREMENT_COMPLETE',
                    'user_id' => $userId,
                    'date'    => $date,
                    'success' => $success,
                    'rows'    => $stmt->rowCount()
                ]
            );

            return $success;

        } catch (Throwable $e) {

            Logger::write(
                'advert_usage_error',
                [
                    'step'    => 'INCREMENT_FAILED',
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine(),
                    'file'    => $e->getFile()
                ]
            );

            return false;
        }
    }

    /**
     * Get advert count
     */
    public function count(int $userId): int
    {
        $usage = $this->today($userId);

        return (int)($usage['adverts_count'] ?? 0);
    }

    /**
     * Reset advert usage after upgrade
     */
    public function reset(int $userId): bool
    {
        try {

            Logger::write(
                'advert_usage_debug',
                [
                    'step'    => 'RESET_START',
                    'user_id' => $userId
                ]
            );

            $stmt = $this->db->prepare(
                "
                DELETE FROM {$this->table}
                WHERE user_id = ?
                "
            );

            $success = $stmt->execute([
                $userId
            ]);

            Logger::write(
                'advert_usage_debug',
                [
                    'step'    => 'RESET_COMPLETE',
                    'user_id' => $userId,
                    'success' => $success
                ]
            );

            return $success;

        } catch (Throwable $e) {

            Logger::write(
                'advert_usage_error',
                [
                    'step'    => 'RESET_FAILED',
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine(),
                    'file'    => $e->getFile()
                ]
            );

            return false;
        }
    }

    /**
     * Check daily limit
     */
    public function exceeded(int $userId, int $limit): bool
    {
        $count = $this->count($userId);

        Logger::write(
            'advert_usage_debug',
            [
                'step'    => 'LIMIT_CHECK',
                'user_id' => $userId,
                'count'   => $count,
                'limit'   => $limit
            ]
        );

        return $count >= $limit;
    }
}