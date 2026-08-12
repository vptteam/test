<?php

declare(strict_types=1);

namespace Core;


class HandlerFactory
{

    /**
     * ---------------------------------------------------------
     * CREATE HANDLER INSTANCE
     *
     * Converts workflow step into handler class.
     *
     * Example:
     *
     * seller_delivery
     *      |
     *      ↓
     * SellerDeliveryHandler
     *
     * ---------------------------------------------------------
     */
    public static function make(

        string $module,

        string $step

    ): object {


        Logger::write(

            'handler_factory_debug',

            [

                'step'            => 'START',

                'module_received' => $module,

                'step_received'   => $step

            ]

        );



        /*
        |--------------------------------------------------------------------------
        | Convert workflow step to class name
        |--------------------------------------------------------------------------
        |
        | seller_delivery
        |
        | becomes
        |
        | SellerDeliveryHandler
        |
        |--------------------------------------------------------------------------
        */


        $handler = str_replace(

            ' ',

            '',

            ucwords(

                str_replace(

                    '_',

                    ' ',

                    strtolower($step)

                )

            )

        ) . 'Handler';



        $class =

            "Modules\\{$module}\\Handlers\\{$handler}";




        Logger::write(

            'handler_factory_debug',

            [

                'step'  => 'CLASS_CREATED',

                'class' => $class

            ]

        );




        /*
        |--------------------------------------------------------------------------
        | Check Class Exists
        |--------------------------------------------------------------------------
        */


        if (!class_exists($class)) {


            Logger::write(

                'handler_factory_error',

                [

                    'step'  => 'HANDLER_NOT_FOUND',

                    'class' => $class

                ]

            );



            throw new \RuntimeException(

                "Handler not found: {$class}"

            );


        }





        /*
        |--------------------------------------------------------------------------
        | Create Object
        |--------------------------------------------------------------------------
        */


        try {


            $object = new $class();



        }

        catch(\Throwable $e) {


            Logger::write(

                'handler_factory_error',

                [

                    'step'    => 'OBJECT_CREATE_FAILED',

                    'class'   => $class,

                    'message' => $e->getMessage(),

                    'file'    => $e->getFile(),

                    'line'    => $e->getLine()

                ]

            );


            throw $e;


        }





        /*
        |--------------------------------------------------------------------------
        | Validate Handler Interface
        |--------------------------------------------------------------------------
        |
        | Every handler must contain either:
        |
        | execute()
        |
        | or
        |
        | start()
        |
        |--------------------------------------------------------------------------
        */


        


            


        if (

    !method_exists($object, 'execute')

    &&

    !method_exists($object, 'start')

    &&

    !method_exists($object, 'validate')

) {

    Logger::write(
        'handler_factory_error',
        [
            'step'    => 'INVALID_HANDLER',
            'handler' => get_class($object)
        ]
    );

    throw new \RuntimeException(
        "Invalid handler: {$class}"
    );

}





        Logger::write(

            'handler_factory_debug',

            [

                'step'    => 'OBJECT_CREATED',

                'handler' => get_class($object)

            ]

        );




        return $object;


    }


}