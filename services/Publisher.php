<?php

declare(strict_types=1);

namespace Services;

use Core\Logger;
use Throwable;

class Publisher
{

    protected array $publishers = [];


    public function __construct()
    {

        try {


            $config = require __DIR__ . '/../config/publishers.php';



            foreach($config as $name=>$class){


                if(!class_exists($class)){


                    Logger::write(
                        'publisher_error',
                        [
                            'step'=>'CLASS_NOT_FOUND',
                            'publisher'=>$class
                        ]
                    );


                    continue;

                }



                $this->publishers[$name] = new $class();


            }



            Logger::write(
                'publisher_debug',
                [
                    'step'=>'PUBLISHERS_LOADED',
                    'publishers'=>array_keys($this->publishers)
                ]
            );


        }
        catch(Throwable $e){


            Logger::write(
                'publisher_error',
                [
                    'step'=>'CONSTRUCTOR_FAILED',
                    'message'=>$e->getMessage(),
                    'line'=>$e->getLine(),
                    'file'=>$e->getFile()
                ]
            );


            throw $e;

        }


    }





    /**
 * Publish listing everywhere
 */
public function publish(
    int $listingId
): bool {


    $success = false;



    /*
    |--------------------------------------------------------------------------
    | Load Listing
    |--------------------------------------------------------------------------
    */

    try {


        $listingModel = new \Models\Listing();


        $listing = $listingModel->find(

            $listingId

        );



        if(!$listing){


            Logger::write(
                'publisher_error',
                [
                    'step'=>'LISTING_NOT_FOUND',
                    'listing_id'=>$listingId
                ]
            );


            return false;

        }



        Logger::write(
            'publisher_debug',
            [
                'step'=>'LISTING_LOADED',
                'listing'=>$listing
            ]
        );


    }
    catch(Throwable $e){


        Logger::write(
            'publisher_error',
            [
                'step'=>'LISTING_LOAD_FAILED',
                'message'=>$e->getMessage(),
                'line'=>$e->getLine()
            ]
        );


        return false;


    }







    /*
    |--------------------------------------------------------------------------
    | Publish To Channels
    |--------------------------------------------------------------------------
    */


    foreach($this->publishers as $name=>$publisher){



        try {


            if(method_exists($publisher,'publish')){


                $result = $publisher->publish(

                    $listing

                );




                if($result){


                    $success = true;


                }




                Logger::write(
                    'publisher_debug',
                    [
                        'step'=>'CHANNEL_PUBLISHED',
                        'channel'=>$name,
                        'result'=>$result
                    ]
                );



            }



        }
        catch(Throwable $e){


            Logger::write(
                'publisher_error',
                [
                    'step'=>'CHANNEL_FAILED',
                    'channel'=>$name,
                    'message'=>$e->getMessage(),
                    'line'=>$e->getLine()
                ]
            );


        }



    }





    return $success;


}

}