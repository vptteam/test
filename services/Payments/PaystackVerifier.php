<?php

declare(strict_types=1);

namespace Services\Payments;

use Core\Logger;
use Throwable;


class PaystackVerifier
{


    protected string $endpoint;


    protected string $secret;




    public function __construct()
    {


        $this->secret =
            PAYSTACK_SECRET_KEY;


        $this->endpoint =
            PAYSTACK_BASE_URL
            .
            '/transaction/verify/';




    }








    /**
     * Verify Paystack Transaction
     */
    public function verify(

        string $reference

    ): array {


        try {


            $url =
                $this->endpoint
                .
                $reference;




            Logger::write(

                'paystack_verify',

                [

                    'step'=>'VERIFY_START',

                    'reference'=>$reference

                ]

            );







            $ch=curl_init($url);



            curl_setopt_array($ch,[


                CURLOPT_RETURNTRANSFER=>true,


                CURLOPT_HTTPHEADER=>[


                    'Authorization: Bearer '
                    .
                    $this->secret,


                    'Content-Type: application/json'


                ],


                CURLOPT_CONNECTTIMEOUT => 10,
CURLOPT_TIMEOUT => 60


            ]);







            $response=curl_exec($ch);



            $http=curl_getinfo(

                $ch,

                CURLINFO_HTTP_CODE

            );

            if ($http !== 200) {

    return [

        'success' => false,

        'message' => 'Paystack returned HTTP '.$http

    ];

}

            $error=curl_error($ch);




            Logger::write(

                'paystack_verify',

                [

                    'step'=>'VERIFY_RESPONSE',

                    'http'=>$http,

                    'response'=>$response,

                    'error'=>$error

                ]

            );







            if($error){


                return [

                    'success'=>false,

                    'message'=>$error

                ];


            }







            $data=json_decode(

                $response,

                true

            );







            if(

                !($data['status'] ?? false)

            ){


                return [

                    'success'=>false,

                    'message'=>

                        $data['message']
                        ??
                        'Verification failed'

                ];


            }







            $transaction =
                $data['data'];






            if(

                ($transaction['status'] ?? '')
                !==
                'success'

            ){


                return [

                    'success'=>false,

                    'message'=>
                        'Payment not successful'

                ];


            }







            return [

                'success'=>true,
                
                'data' => $transaction,

                'raw' => $data

            ];





        }
        catch(Throwable $e){


            Logger::write(

                'paystack_verify_error',

                [

                    'message'=>$e->getMessage(),

                    'line'=>$e->getLine()

                ]

            );



            return [

                'success'=>false,

                'message'=>'Verification error'

            ];


        }



    }




}