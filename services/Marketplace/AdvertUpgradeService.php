<?php

declare(strict_types=1);

namespace Services\Marketplace;


use Models\AdvertUpgradePayment;
use Models\SellerPackage;
use Models\User;

use Core\Logger;

use Services\Payments\PaystackGateway;

use Throwable;



class AdvertUpgradeService
{


    protected AdvertUpgradePayment $payments;

    protected SellerPackage $packages;

    protected User $users;

    protected PaystackGateway $paystack;



    public function __construct()
    {

        $this->payments = new AdvertUpgradePayment();

        $this->packages = new SellerPackage();

        $this->users = new User();

        $this->paystack = new PaystackGateway();

    }





    /**
     * Create advert upgrade payment
     */
    public function createUpgrade(

    int|string $userId,

    string $packageSlug

): array {


    $userId = (int)$userId;


        try {


            Logger::write(
                'advert_upgrade',
                [
                    'step'=>'START',
                    'telegram_id'=>$userId,
                    'package'=>$packageSlug
                ]
            );





            /*
            |--------------------------------------------------------------------------
            | Load Package
            |--------------------------------------------------------------------------
            */


            $package =

                $this->packages->findActiveBySlug(
                    $packageSlug
                );



            if(!$package){


                return [

                    'success'=>false,

                    'message'=>'Seller package not found.'

                ];

            }






            if((float)$package['price'] <= 0){


                return [

                    'success'=>false,

                    'message'=>'Invalid package.'

                ];

            }







            /*
            |--------------------------------------------------------------------------
            | Find User
            |--------------------------------------------------------------------------
            */


            $user = null;



            if(method_exists($this->users,'findByTelegramId')){


                $user =
                    $this->users->findByTelegramId(
                        $userId
                    );
                    
                    Logger::write(
    'advert_upgrade',
    [
        'step'=>'USER_LOOKUP',
        'telegram_id'=>$userId,
        'result'=>$user
    ]
);

            }





            /*
            |--------------------------------------------------------------------------
            | Fallback Search
            |--------------------------------------------------------------------------
            */


            if(!$user && method_exists($this->users,'find')){


                $user =
                    $this->users->find(
                        $userId
                    );

            }






            /*
            |--------------------------------------------------------------------------
            | Create User If Missing
            |--------------------------------------------------------------------------
            */


            if(!$user){


                Logger::write(

                    'advert_upgrade',

                    [

                        'step'=>'USER_MISSING_CREATING',

                        'telegram_id'=>$userId

                    ]

                );




                if(method_exists($this->users,'findOrCreateTelegramUser')){


                    $user =

                        $this->users->findOrCreateTelegramUser(

                            $userId

                        );

                }


            }






            if(!$user){


                return [

                    'success'=>false,

                    'message'=>'User account not found.'

                ];

            }






            Logger::write(

                'advert_upgrade',

                [

                    'step'=>'USER_FOUND',

                    'user'=>$user

                ]

            );









            /*
            |--------------------------------------------------------------------------
            | Existing Pending Payment
            |--------------------------------------------------------------------------
            */


            if(method_exists($this->payments,'pendingForUser')){


                $pending =
    $this->payments->pendingForUser(
        (int)$userId
    );




                if($pending){


                    return [

                        'success'=>true,

                        'message'=>'Existing payment found.',

                        'payment_url'=>$pending['payment_url'] ?? null,

                        'reference'=>$pending['reference']

                    ];

                }


            }








            /*
            |--------------------------------------------------------------------------
            | Create Reference
            |--------------------------------------------------------------------------
            */


            $reference =

                'ADV-'

                .date('YmdHis')

                .'-'

                .$user['id']

                .'-'

                .random_int(1000,9999);









            /*
            |--------------------------------------------------------------------------
            | Create Payment Record
            |--------------------------------------------------------------------------
            */


            $created =

                $this->payments->create(

                    [

                        'user_id'=>$user['id'],

                        'package_id'=>$package['id'],

                        'reference'=>$reference,

                        'amount'=>$package['price']

                    ]

                );





            if(!$created){


                return [

                    'success'=>false,

                    'message'=>'Unable to create payment record.'

                ];

            }






            /*
            |--------------------------------------------------------------------------
            | Paystack
            |--------------------------------------------------------------------------
            */


            $email =

                $user['email']

                ??

                'sendamfitness@gmail.com';







            $payment =

                $this->paystack->initialize(

                    (int)$package['price'],

                    $email,

                    $reference,

                    PAYSTACK_CALLBACK_URL

                );






            if(!($payment['success'] ?? false)){



                Logger::write(

                    'advert_upgrade',

                    [

                        'step'=>'PAYSTACK_FAILED',

                        'response'=>$payment

                    ]

                );



                return [

                    'success'=>false,

                    'message'=>

                        $payment['message']

                        ??

                        'Payment failed.'

                ];

            }








            /*
            |--------------------------------------------------------------------------
            | Save URL
            |--------------------------------------------------------------------------
            */


            if(method_exists($this->payments,'savePaymentUrl')){


                $this->payments->savePaymentUrl(

                    $reference,

                    $payment['authorization_url']

                );

            }








            Logger::write(

                'advert_upgrade',

                [

                    'step'=>'SUCCESS',

                    'reference'=>$reference

                ]

            );






            return [

                'success'=>true,

                'reference'=>$reference,

                'payment_url'=>$payment['authorization_url'],

                'amount'=>$package['price'],

                'package'=>$package

            ];






        }
        catch(Throwable $e){



            Logger::write(

                'advert_upgrade_error',

                [

                    'message'=>$e->getMessage(),

                    'file'=>$e->getFile(),

                    'line'=>$e->getLine()

                ]

            );



            return [

                'success'=>false,

                'message'=>'Unable to create advert upgrade payment.'

            ];

        }



    }


}