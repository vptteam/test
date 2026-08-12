<?php

declare(strict_types=1);

namespace Models;

use Core\Database;
use Core\Logger;
use PDO;
use Throwable;
use Models\AdvertUsage;

class SellerSubscription
{
    protected string $table = 'seller_subscriptions';

    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->connection();
    }

    /**
     * Get current active subscription
     */
    public function active(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "
            SELECT
                ss.*,
                sp.name,
                sp.slug,
                sp.daily_post_limit

            FROM seller_subscriptions ss

            INNER JOIN seller_packages sp
                ON sp.id = ss.package_id

            WHERE ss.user_id = ?

            AND ss.status='active'

            AND ss.expires_at >= NOW()

            ORDER BY ss.id DESC

            LIMIT 1
            "
        );

        $stmt->execute([$userId]);

        $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$subscription) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Daily reset
        |--------------------------------------------------------------------------
        */

        $today = (new \DateTime(
    'now',
    new \DateTimeZone('Africa/Lagos')
))->format('Y-m-d');

        if (
            empty($subscription['last_reset_date'])
            || $subscription['last_reset_date'] !== $today
        ) {

            $this->resetIfNewDay(
                (int)$subscription['id']
            );

            $stmt->execute([$userId]);

            $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return $subscription ?: null;
    }

    /**
     * Create subscription
     */
    public function create(array $data): bool
    {
        $stmt = $this->db->prepare(
            "
            INSERT INTO {$this->table}
            (
                user_id,
                package_id,
                daily_post_limit,
                adverts_limit,
                adverts_used_today,
                last_reset_date,
                payment_reference,
                starts_at,
                expires_at,
                status
            )

            VALUES
            (
                :user_id,
                :package_id,
                :daily_post_limit,
                :adverts_limit,
                0,
                :last_reset_date,
                :payment_reference,
                :starts_at,
                :expires_at,
                :status
            )
            "
        );

        return $stmt->execute([

            'user_id'           => $data['user_id'],

            'package_id'        => $data['package_id'],

            'daily_post_limit' => $data['daily_post_limit'] === null
    ? null
    : (int)$data['daily_post_limit'],

'adverts_limit' => $data['daily_post_limit'] === null
    ? null
    : (int)$data['daily_post_limit'],

            'last_reset_date' => (new \DateTime(
    'now',
    new \DateTimeZone('Africa/Lagos')
))->format('Y-m-d'),

            'payment_reference' => $data['payment_reference'] ?? null,

            'starts_at'         => $data['starts_at'],

            'expires_at'        => $data['expires_at'],

            'status'            => $data['status'] ?? 'active'

        ]);
    }

    /**
     * Reset usage when a new day starts
     */
    public function resetIfNewDay(int $subscriptionId): bool
{
    try {

        $today = (new \DateTime(
            'now',
            new \DateTimeZone('Africa/Lagos')
        ))->format('Y-m-d');

        $stmt = $this->db->prepare("
            UPDATE {$this->table}
            SET
                adverts_used_today = 0,
                last_reset_date = ?
            WHERE id = ?
            AND (
                last_reset_date IS NULL
                OR last_reset_date <> ?
            )
        ");

        return $stmt->execute([
            $today,
            $subscriptionId,
            $today
        ]);

    } catch (Throwable $e) {

        Logger::write(
            'seller_subscription_reset_error',
            [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString()
            ]
        );

        return false;
    }
}

    /**
     * Increment today's advert usage
     */
    public function incrementUsage(int $subscriptionId): bool
    {
        try {

            $stmt = $this->db->prepare(
                "
                UPDATE {$this->table}

                SET
    adverts_used_today = adverts_used_today + 1,
    adverts_used = adverts_used + 1

                WHERE id = ?
                "
            );

            return $stmt->execute([
                $subscriptionId
            ]);

        } catch (Throwable $e) {

            Logger::write(
                'seller_subscription_increment_error',
                [
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine()
                ]
            );

            return false;
        }
    }
        /**
     * Get today's advert usage
     */
    public function advertsUsedToday(int $subscriptionId): int
    {
        $stmt = $this->db->prepare(
            "
            SELECT adverts_used_today

            FROM {$this->table}

            WHERE id = ?

            LIMIT 1
            "
        );

        $stmt->execute([
            $subscriptionId
        ]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Remaining adverts today
     */
    public function remainingToday(int $subscriptionId): int
    {
        $stmt = $this->db->prepare(
            "
            SELECT
                daily_post_limit,
                adverts_used_today

            FROM {$this->table}

            WHERE id = ?

            LIMIT 1
            "
        );

        $stmt->execute([
            $subscriptionId
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
    return 0;
}

if ($row['daily_post_limit'] === null) {
    return PHP_INT_MAX;
}

return max(
    0,
    (int)$row['daily_post_limit']
    -
    (int)$row['adverts_used_today']
);


    }

    /**
     * Reset usage manually
     */
    public function resetUsage(int $subscriptionId): bool
    {
        try {
            
            $today = (new \DateTime(
    'now',
    new \DateTimeZone('Africa/Lagos')
))->format('Y-m-d');

            $stmt = $this->db->prepare(
    "
    UPDATE {$this->table}

    SET
        adverts_used_today = 0,
        last_reset_date = :today

    WHERE id = :id
    "
);

            return $stmt->execute([
    'today' => $today,
    'id'    => $subscriptionId
]);

        } catch (Throwable $e) {

            Logger::write(
                'seller_subscription_reset_usage_error',
                [
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine(),
                    'file'    => $e->getFile()
                ]
            );

            return false;
        }
    }

    /**
     * Find subscription by ID
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "
            SELECT *

            FROM {$this->table}

            WHERE id = ?

            LIMIT 1
            "
        );

        $stmt->execute([
            $id
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Expire subscriptions
     */
    public function expireOld(): bool
    {
        $stmt = $this->db->prepare(
            "
            UPDATE {$this->table}

            SET status='expired'

            WHERE status='active'

            AND expires_at < NOW()
            "
        );

        return $stmt->execute();
    }

    /**
     * Cancel subscription
     */
    public function cancel(int $subscriptionId): bool
    {
        $stmt = $this->db->prepare(
            "
            UPDATE {$this->table}

            SET status='cancelled'

            WHERE id=?
            "
        );

        return $stmt->execute([
            $subscriptionId
        ]);
    }
    
    public function canCreateAdvert(int $userId): array
{
    /*
    |--------------------------------------------------------------------------
    | Active Paid Subscription
    |--------------------------------------------------------------------------
    */

    $subscription = $this->active($userId);

    if ($subscription) {

        $remaining = $this->remainingToday(
            (int)$subscription['id']
        );

        if ($remaining <= 0) {

            return [
                'success' => false,
                'message' =>
                    "🚫 You've reached today's advert limit.\n\n"
                    . "Reply UPGRADE to increase your daily posting limit."
            ];

        }

        return [
            'success'      => true,
            'subscription' => $subscription,
            'remaining'    => $remaining,
            'type'         => 'paid'
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Free Seller Package
    |--------------------------------------------------------------------------
    */

    $packageModel = new SellerPackage();

    $freePackage = $packageModel->findBySlug('free');

    if (!$freePackage) {

        Logger::write(
            'seller_subscription_error',
            [
                'step' => 'FREE_PACKAGE_NOT_FOUND'
            ]
        );

        return [
            'success' => false,
            'message' => "Free seller package is not configured."
        ];
    }

    $limit = (int)$freePackage['daily_post_limit'];

    $usage = new AdvertUsage();

    $count = $usage->count($userId);

    if ($count >= $limit) {

        return [
            'success' => false,
            'message' =>
                "🚫 You've reached your free daily listing limit.\n\n"
                . "Upgrade your account to:\n\n"
                . "✅ Post more adverts\n"
                . "📢 Get more visibility\n"
                . "🚀 Reach more buyers faster\n\n"
                . "Reply with:\n\n"
                . "UPGRADE"
        ];

    }

    return [
        'success'   => true,
        'remaining' => $limit - $count,
        'type'      => 'free'
    ];
}

}