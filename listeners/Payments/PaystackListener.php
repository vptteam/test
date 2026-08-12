<?php

declare(strict_types=1);

namespace Listeners\Payments;

use Controllers\AdvertPaymentController;
use Core\Logger;
use Throwable;


class PaystackListener
{


    public function handle(): void
    {


        /*
        |--------------------------------------------------------------------------
        | Request Received
        |--------------------------------------------------------------------------
        */

        Logger::write(

            'paystack_listener',

            [

                'step'=>'REQUEST_RECEIVED',

                'method'=>$_SERVER['REQUEST_METHOD'] ?? null,

                'uri'=>$_SERVER['REQUEST_URI'] ?? null,

                'query'=>$_GET,

                'post'=>$_POST,

                'time'=>date('Y-m-d H:i:s')

            ]

        );





        try {


            /*
            |--------------------------------------------------------------------------
            | Create Controller
            |--------------------------------------------------------------------------
            */


            Logger::write(

                'paystack_listener',

                [

                    'step'=>'BEFORE_CONTROLLER_CREATE'

                ]

            );





            try {


                $controller =
                    new AdvertPaymentController();



                Logger::write(

                    'paystack_listener',

                    [

                        'step'=>'CONTROLLER_CREATED',

                        'class'=>get_class($controller)

                    ]

                );


            }

            catch(Throwable $e){


                Logger::write(

                    'paystack_listener_error',

                    [

                        'step'=>'CONTROLLER_CREATION_FAILED',

                        'message'=>$e->getMessage(),

                        'file'=>$e->getFile(),

                        'line'=>$e->getLine(),

                        'trace'=>$e->getTraceAsString()

                    ]

                );


                throw $e;


            }









            /*
            |--------------------------------------------------------------------------
            | Execute Callback
            |--------------------------------------------------------------------------
            */


            Logger::write(

                'paystack_listener',

                [

                    'step'=>'BEFORE_CALLBACK_EXECUTION'

                ]

            );






            $controller->callback();







            Logger::write(

                'paystack_listener',

                [

                    'step'=>'CALLBACK_FINISHED'

                ]

            );






        }

        catch(Throwable $e){



            Logger::write(

                'paystack_listener_error',

                [

                    'step'=>'GENERAL_FAILURE',

                    'message'=>$e->getMessage(),

                    'file'=>$e->getFile(),

                    'line'=>$e->getLine(),

                    'trace'=>$e->getTraceAsString(),

                    'query'=>$_GET

                ]

            );





            http_response_code(500);



            echo "Payment processing error";



        }


    }


}