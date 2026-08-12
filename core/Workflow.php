<?php

declare(strict_types=1);

namespace Core;


class Workflow
{

    /**
     * Debug helper
     */
    private static function debug(
        string $step,
        array $data = []
    ): void {

        try {

            Logger::write(
                'workflow_trace',
                array_merge(
                    [
                        'time' => date('Y-m-d H:i:s'),
                        'step' => $step
                    ],
                    $data
                )
            );


        } catch (\Throwable $e) {

            error_log(
                'WORKFLOW DEBUG LOGGER FAILED: '
                . $e->getMessage()
            );

        }

    }



    /**
     * Load workflow definition
     */
    public static function load(
        string $module,
        string $flow
    ): array {


        self::debug(
            'LOAD_START',
            [
                'module'=>$module,
                'flow'=>$flow
            ]
        );


        $file =

            BASE_PATH .

            "/modules/{$module}/Workflows/{$flow}.php";


        self::debug(
            'WORKFLOW_FILE_CREATED',
            [
                'file'=>$file
            ]
        );



        if (!file_exists($file)) {


            self::debug(
                'WORKFLOW_FILE_NOT_FOUND',
                [
                    'file'=>$file
                ]
            );


            return [];

        }



        self::debug(
            'WORKFLOW_FILE_EXISTS',
            [
                'file'=>$file
            ]
        );



        try {


            self::debug(
                'BEFORE_WORKFLOW_REQUIRE'
            );


           self::debug(
    'BEFORE_INCLUDE',
    [
        'file'=>$file
    ]
);


ob_start();

$result = include $file;

$output = ob_get_clean();


self::debug(
    'AFTER_INCLUDE',
    [
        'result_type'=>gettype($result),
        'result'=>$result,
        'output'=>$output
    ]
);


$workflow = $result;



            self::debug(
                'AFTER_WORKFLOW_REQUIRE',
                [
                    'type'=>gettype($workflow),
                    'workflow'=>$workflow
                ]
            );



            if (!is_array($workflow)) {


                self::debug(
                    'WORKFLOW_NOT_ARRAY',
                    [
                        'returned_type'=>gettype($workflow)
                    ]
                );


                return [];

            }



            return $workflow;



        } catch (\Throwable $e) {



            self::debug(
                'WORKFLOW_REQUIRE_ERROR',
                [
                    'message'=>$e->getMessage(),
                    'file'=>$e->getFile(),
                    'line'=>$e->getLine()
                ]
            );


            return [];

        }


    }




    /**
     * Find next workflow step
     */
    public static function next(
        array $conversation
    ): ?string {


        self::debug(
            'NEXT_START',
            [
                'conversation'=>$conversation
            ]
        );



        try {


            $module = ucfirst(
                $conversation['module'] ?? ''
            );


            $flow = 
                $conversation['flow'] ?? '';



            $currentStep =
                $conversation['step'] ?? '';



            self::debug(
                'NEXT_PARAMETERS',
                [
                    'module'=>$module,
                    'flow'=>$flow,
                    'current_step'=>$currentStep
                ]
            );




            $workflow = self::load(
                $module,
                $flow
            );



            self::debug(
                'AFTER_LOAD',
                [
                    'workflow'=>$workflow
                ]
            );




            if (!$workflow) {


                self::debug(
                    'WORKFLOW_EMPTY'
                );


                return null;

            }




            self::debug(
                'BEFORE_ARRAY_SEARCH',
                [
                    'search'=>$currentStep
                ]
            );



            $current = array_search(

                $currentStep,

                $workflow,

                true

            );



            self::debug(
                'AFTER_ARRAY_SEARCH',
                [
                    'position'=>$current
                ]
            );




            if ($current === false) {


                self::debug(
                    'CURRENT_STEP_NOT_FOUND'
                );


                return null;

            }





            $next =

                $workflow[$current + 1]

                ??

                null;




            self::debug(
                'NEXT_STEP_CALCULATED',
                [
                    'current_position'=>$current,
                    'next'=>$next
                ]
            );




            return $next;



        } catch (\Throwable $e) {



            self::debug(
                'NEXT_EXCEPTION',
                [
                    'message'=>$e->getMessage(),
                    'file'=>$e->getFile(),
                    'line'=>$e->getLine()
                ]
            );


            return null;

        }


    }


}