<?php

declare(strict_types=1);

namespace Modules\Profile\Handlers;

use Models\User;
use Models\Listing;
use Models\SellerSubscription;
use Core\ReplyInterface;

class ProfileHandler
{
    protected User $users;

    protected Listing $listing;

    protected SellerSubscription $subscription;

    public function __construct()
    {
        $this->users = new User();

        $this->listing = new Listing();

        $this->subscription = new SellerSubscription();
    }

    public function show(
        ReplyInterface $reply,
        int $userId,
        string $phone
    ): void
    {
        $user = $this->users->find($userId);

        if (!$user) {

            $reply->text(
                $phone,
                "Profile not found."
            );

            return;
        }

        $listingCount =
            $this->listing->countByUser($userId);

        $package =
            $this->subscription->active($userId);

        $packageName =
            $package['package_name']
            ?? 'Free Seller';

        $used =
            $package['adverts_used_today']
            ?? 0;

        $limit =
            $package['daily_post_limit']
            ?? 1;

        $joined =
            date(
                'd M Y',
                strtotime($user['created_at'])
            );

        $message =
"👤 SENDAM PROFILE

🆔 Sendam ID
{$user['sendam_id']}

📱 Platform
".ucfirst($user['platform'])."

🪪 Platform ID
{$user['platform_id']}

👤 Name
{$user['name']}

✅ Verification
".ucfirst($user['verification_status'])."


📅 Joined
{$joined}

━━━━━━━━━━━━━━

📦 Listings
{$listingCount}

🚀 Seller Package
{$packageName}

📈 Daily Usage
{$used} / {$limit}

━━━━━━━━━━━━━━

Completed Sales
{$user['completed_sales']}

Completed Purchases
{$user['completed_buys']}";

        $reply->text(
            $phone,
            $message
        );
    }
}