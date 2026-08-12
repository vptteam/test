<?php

declare(strict_types=1);

namespace Services\Marketplace;

use Models\SellerSubscription;
use Models\SellerPackage;
use Core\Logger;
use Throwable;


class AdvertQuotaService
{


    protected SellerSubscription $subscription;


    protected SellerPackage $packages;





   public function __construct()
{
    Logger::write(
        'advert_quota_debug',
        [
            'step' => 'CONSTRUCTOR_START'
        ]
    );

    $this->subscription = new SellerSubscription();

    Logger::write(
        'advert_quota_debug',
        [
            'step' => 'SELLER_SUBSCRIPTION_CREATED'
        ]
    );

    $this->packages = new SellerPackage();

    Logger::write(
        'advert_quota_debug',
        [
            'step' => 'SELLER_PACKAGE_CREATED'
        ]
    );
}







    /**
     * Check if user can create advert
     */
    public function canPost(
        int $userId
    ): array {


        try {


            /*
            |--------------------------------------------------------------------------
            | Load active subscription
            |--------------------------------------------------------------------------
            */


            $subscription = $this->subscription->active(

                $userId

            );





            /*
            |--------------------------------------------------------------------------
            | No paid subscription
            | Use free package
            |--------------------------------------------------------------------------
            */


            if(!$subscription){


                $package = $this->packages->findBySlug(

                    'free'

                );


                if(!$package){


                    return [

                        'allowed'=>false,

                        'message'=>
                            'No seller package configured.',

                        'upgrade'=>false

                    ];


                }



                $dailyLimit =
                    $package['daily_post_limit'];



            }else{


                $dailyLimit =
                    $subscription['daily_post_limit'];


            }








            /*
            |--------------------------------------------------------------------------
            | Unlimited package
            |--------------------------------------------------------------------------
            */


            if(
                $dailyLimit === null
            ){


                return [

    'allowed'   => true,
    'remaining' => PHP_INT_MAX,
    'used'      => 0,
    'limit'     => null,
    'unlimited' => true

];


            }








            /*
|--------------------------------------------------------------------------
| Today's usage
|--------------------------------------------------------------------------
*/

$used = (int)$subscription['adverts_used_today'];

$remaining = $dailyLimit - $used;






            /*
            |--------------------------------------------------------------------------
            | Limit reached
            |--------------------------------------------------------------------------
            */


            if(
                $remaining <= 0
            ){


                Logger::write(

                    'advert_quota_limit',

                    [

                        'user_id'=>$userId,

                        'limit'=>$dailyLimit,

                        'used'=>$used

                    ]

                );



                return [

    'allowed'=>false,

    'message' =>
    "🚫 You’ve reached your free listing limit.\n\n" .
    "Upgrade your account to:\n\n" .
    "✅ Post more items\n" .
    "📢 Get more exposure for your listings\n" .
    "🚀 Reach more buyers faster\n\n" .
    "Reply with:\n\n" .
    "UPGRADE\n\n" .
    "or\n\n" .
    "CANCEL",

    'upgrade'=>true,

    'action'=>'upgrade',

    'used'=>$used,

    'limit'=>$dailyLimit

];



            }









            return [

    'allowed'=>true,

    'remaining'=>$remaining,

    'used'=>$used,

    'limit'=>$dailyLimit,

    'package'=>$package ?? null

];




        }catch(Throwable $e){



            Logger::write(

                'advert_quota_error',

                [

                    'user_id'=>$userId,

                    'message'=>$e->getMessage(),

                    'line'=>$e->getLine()

                ]

            );



            return [

                'allowed'=>false,

                'message'=>'Unable to check advert limit.',

                'upgrade'=>false

            ];

        }



    }









    /**
 * Consume one advert slot
 */
public function consume(int $userId): bool
{
    try {

        $subscription = $this->subscription->active($userId);

        if (!$subscription) {
            return true; // free users
        }

        if ($subscription['daily_post_limit'] === null) {
            return true; // unlimited package
        }

        return $this->subscription->incrementUsage(
            (int)$subscription['id']
        );

    } catch (Throwable $e) {

        Logger::write(
            'advert_consume_error',
            [
                'user_id'=>$userId,
                'message'=>$e->getMessage(),
                'line'=>$e->getLine()
            ]
        );

        return false;
    }
}








    /**
     * Get current usage information
     */
    public function status(
        int $userId
    ): array {


        $check = $this->canPost(

            $userId

        );


        return [

    'allowed'   => $check['allowed'] ?? false,
    'remaining' => $check['remaining'] ?? 0,
    'limit'     => $check['limit'] ?? null,
    'used'      => $check['used'] ?? 0,
    'unlimited' => $check['unlimited'] ?? false

];


    }




}