<?php

declare(strict_types=1);

namespace Core;

use Models\Conversation;
use Models\SellerSubscription;
use Throwable;


/**
 * WorkflowExecutor
 *
 * Handles:
 *
 * - Active conversations
 * - Workflow state
 * - Handler execution
 * - Data persistence
 * - Step movement
 */
class WorkflowExecutor
{


    /**
     * ---------------------------------------------------------
     * RUN ACTIVE WORKFLOW
     * ---------------------------------------------------------
     */
    public function run(

        array $user,

        array $message,

        ReplyInterface $reply

    ): bool {


        try {


            /*
            |--------------------------------------------------------------------------
            | Normalize Message
            |--------------------------------------------------------------------------
            */


            $text = trim(

                (string)(

                    $message['text']

                    ??

                    ''

                )

            );



            Logger::write(

                'workflow_trace',

                [

                    'step'     => 'START',

                    'user_id'  => $user['id'] ?? null,

                    'platform' => $message['platform'] ?? null,

                    'text'     => $text,

                    'type'     => $message['type'] ?? null

                ]

            );




            /*
            |--------------------------------------------------------------------------
            | Load Active Conversation
            |--------------------------------------------------------------------------
            */


            $conversationModel = new Conversation();



            $conversation =

                $conversationModel->active(

                    (int)$user['id']

                );




            if (!$conversation) {


                Logger::write(

                    'workflow_trace',

                    [

                        'step'=>'NO_ACTIVE_CONVERSATION'

                    ]

                );


                return false;


            }





            Logger::write(

                'workflow_trace',

                [

                    'step'=>'ACTIVE_CONVERSATION',

                    'conversation'=>$conversation

                ]

            );





            /*
            |--------------------------------------------------------------------------
            | Validate Conversation
            |--------------------------------------------------------------------------
            */


            if (

                empty($conversation['module'])

                ||

                empty($conversation['step'])

            ) {


                Logger::write(

                    'workflow_error',

                    [

                        'step'=>'INVALID_CONVERSATION',

                        'conversation'=>$conversation

                    ]

                );


                return false;


            }





            /*
            |--------------------------------------------------------------------------
            | Create Handler
            |--------------------------------------------------------------------------
            */


            $handler = HandlerFactory::make(

                $conversation['module'],

                $conversation['step']

            );




            Logger::write(

                'workflow_trace',

                [

                    'step'=>'HANDLER_CREATED',

                    'handler'=>get_class($handler)

                ]

            );





            /*
            |--------------------------------------------------------------------------
            | Load Stored Data
            |--------------------------------------------------------------------------
            */


            $stored = json_decode(

                $conversation['data'] ?? '{}',

                true

            );



            if (!is_array($stored)) {


                $stored = [];


            }





            /*
            |--------------------------------------------------------------------------
            | Always Store User ID
            |--------------------------------------------------------------------------
            */


            $stored['user_id'] =

                (int)$user['id'];





            Logger::write(

                'workflow_trace',

                [

                    'step'=>'DATA_LOADED',

                    'data'=>$stored

                ]

            );





            /*
            |--------------------------------------------------------------------------
            | Marketplace Advert Limit Protection
            |--------------------------------------------------------------------------
            */


            $protectedSteps = [

                'title',

                'location',

                'description',

                'verification',

                'email',

                'photos',

                'publish',

                'complete'

            ];




            if (

                $conversation['module'] === 'Marketplace'

                &&

                in_array(

                    $conversation['step'],

                    $protectedSteps,

                    true

                )

            ) {


                $subscription = new SellerSubscription();



                $check = $subscription->canCreateAdvert(

                    (int)$user['id']

                );



                if (!$check['success']) {


                    $conversationModel->finish(

                        (int)$conversation['id']

                    );



                    $reply->text(

                        $message['phone'],

                        $check['message']

                    );



                    return true;


                }


            }

            /*
            |--------------------------------------------------------------------------
            | Execute Handler
            |--------------------------------------------------------------------------
            |
            | Supports:
            |
            | 1. execute()
            | 2. start()
            |
            | This prevents old handlers from breaking workflow.
            |
            |--------------------------------------------------------------------------
            */


            $result = true;

if (method_exists($handler, 'execute')) {

    Logger::write(
        'workflow_trace',
        [
            'step'    => 'EXECUTE_METHOD_START',
            'handler' => get_class($handler),
            'text'    => $text
        ]
    );

    $result = $handler->execute(
        $reply,
        $user,
        $message,
        $text,
        $stored
    );

} elseif (method_exists($handler, 'validate')) {

    Logger::write(
        'workflow_trace',
        [
            'step'    => 'VALIDATE_METHOD_START',
            'handler' => get_class($handler)
        ]
    );

    $result = $handler->validate($message);

} elseif (method_exists($handler, 'start')) {

    Logger::write(
        'workflow_trace',
        [
            'step'    => 'START_METHOD_START',
            'handler' => get_class($handler),
            'text'    => $text
        ]
    );

    $handler->start(
        $reply,
        $user,
        $message,
        $text
    );

    $result = true;

} else {

    throw new \RuntimeException(
        'Handler has no execute(), validate() or start(): '
        . get_class($handler)
    );

}

            /*
            |--------------------------------------------------------------------------
            | Save Handler Data
            |--------------------------------------------------------------------------
            */


            if (

                method_exists(

                    $handler,

                    'save'

                )

            ) {



                $newData = $handler->save(

                    $message

                );




                Logger::write(

                    'workflow_trace',

                    [

                        'step'=>'HANDLER_SAVE',

                        'data'=>$newData

                    ]

                );




                if (is_array($newData)) {



                    foreach (

                        $newData as $key=>$value

                    ) {



                        /*
                        |--------------------------------------------------------------------------
                        | Merge Photos
                        |--------------------------------------------------------------------------
                        */


                        if (

                            $key === 'photos'

                        ) {



                            if (

                                !isset($stored['photos'])

                                ||

                                !is_array($stored['photos'])

                            ) {


                                $stored['photos'] = [];


                            }




                            if (is_array($value)) {



                                if (

                                    isset($value['media_id'])

                                ) {



                                    $stored['photos'][] = $value;


                                }

                                else {


                                    $stored['photos'] = array_merge(

                                        $stored['photos'],

                                        $value

                                    );


                                }


                            }


                        }

                        else {



                            $stored[$key] = $value;


                        }


                    }


                }


            }





            /*
            |--------------------------------------------------------------------------
            | Save Workflow Data
            |--------------------------------------------------------------------------
            */


            $conversationModel->saveData(

                (int)$conversation['id'],

                $stored

            );




            Logger::write(

                'workflow_trace',

                [

                    'step'=>'DATA_UPDATED',

                    'conversation_id'=>$conversation['id'],

                    'data'=>$stored

                ]

            );





            /*
            |--------------------------------------------------------------------------
            | Stop Failed Handler
            |--------------------------------------------------------------------------
            */


            if ($result === false) {


                Logger::write(

                    'workflow_trace',

                    [

                        'step'=>'HANDLER_FAILED',

                        'handler'=>get_class($handler)

                    ]

                );


                return true;


            }





            /*
            |--------------------------------------------------------------------------
            | Photo Requirement Protection
            |--------------------------------------------------------------------------
            */


            if (

                $conversation['step'] === 'photos'

            ) {



                $photoCount = count(

                    $stored['photos'] ?? []

                );



                Logger::write(

                    'workflow_trace',

                    [

                        'step'=>'PHOTO_PROGRESS',

                        'count'=>$photoCount

                    ]

                );




                if (

                    $photoCount < 5

                    &&

                    empty($stored['photos_complete'])

                ) {



                    $reply->text(

                        $message['phone'],

                        "✅ Photo {$photoCount}/5 received.\n\n".

                        "Send another photo or type DONE if that's all."

                    );



                    return true;


                }


            }

            /*
            |--------------------------------------------------------------------------
            | Terminal Handler Detection
            |--------------------------------------------------------------------------
            |
            | These handlers complete their own process.
            |
            | They should NOT move to another workflow step.
            |
            |--------------------------------------------------------------------------
            */


            $terminalHandlers = [

                'SendItemHandler',

                'SellerDeliveryHandler',

                'ReceivedHandler',

                'ShipHandler',

                'AdminEscrowHandler',

                'BankHandler',

                'BanksHandler',

                'MyBankHandler',

                'DeleteBankHandler'

            ];



            $handlerName =

                (new \ReflectionClass($handler))

                ->getShortName();




            if (

                in_array(

                    $handlerName,

                    $terminalHandlers,

                    true

                )

            ) {



                Logger::write(

                    'workflow_trace',

                    [

                        'step'=>'TERMINAL_HANDLER',

                        'handler'=>$handlerName

                    ]

                );




                $conversationModel->finish(

                    (int)$conversation['id']

                );



                return true;


            }






            /*
            |--------------------------------------------------------------------------
            | Get Next Workflow Step
            |--------------------------------------------------------------------------
            */


            $next = Workflow::next(

                $conversation

            );





            Logger::write(

                'workflow_trace',

                [

                    'step'=>'NEXT_STEP',

                    'next'=>$next

                ]

            );






            /*
            |--------------------------------------------------------------------------
            | Workflow Complete
            |--------------------------------------------------------------------------
            */


            if (!$next) {



                $conversationModel->finish(

                    (int)$conversation['id']

                );




                Logger::write(

                    'workflow_trace',

                    [

                        'step'=>'WORKFLOW_COMPLETED'

                    ]

                );



                return true;


            }






            /*
            |--------------------------------------------------------------------------
            | Move Conversation Forward
            |--------------------------------------------------------------------------
            */


            $conversationModel->updateStep(

                (int)$conversation['id'],

                $next

            );





            Logger::write(

                'workflow_trace',

                [

                    'step'=>'STEP_UPDATED',

                    'next'=>$next

                ]

            );







            /*
            |--------------------------------------------------------------------------
            | Reload Conversation
            |--------------------------------------------------------------------------
            */


            $conversation = $conversationModel->find(

                (int)$conversation['id']

            );





            if (!$conversation) {


                Logger::write(

                    'workflow_error',

                    [

                        'step'=>'RELOAD_FAILED'

                    ]

                );


                return false;


            }







            /*
            |--------------------------------------------------------------------------
            | Reload Stored Data
            |--------------------------------------------------------------------------
            */


            $latestConversation =

                $conversationModel->active(

                    (int)$user['id']

                );





            $latestData = json_decode(

                $latestConversation['data'] ?? '{}',

                true

            );





            if (!is_array($latestData)) {


                $latestData = [];


            }







            /*
            |--------------------------------------------------------------------------
            | Create Next Handler
            |--------------------------------------------------------------------------
            */


            $nextHandler = HandlerFactory::make(

                $conversation['module'],

                $next

            );





            Logger::write(

                'workflow_trace',

                [

                    'step'=>'NEXT_HANDLER_CREATED',

                    'handler'=>get_class($nextHandler)

                ]

            );







            /*
            |--------------------------------------------------------------------------
            | Ask Next Step
            |--------------------------------------------------------------------------
            */


            if (

                method_exists(

                    $nextHandler,

                    'ask'

                )

            ) {

/*
|--------------------------------------------------------------------------
| Prevent entering protected marketplace steps
|--------------------------------------------------------------------------
*/

if (
    $conversation['module'] === 'Marketplace'
    && in_array($next, $protectedSteps, true)
) {

    $subscription = new SellerSubscription();

    $check = $subscription->canCreateAdvert(
        (int)$user['id']
    );

    if (!$check['success']) {

        $conversationModel->finish(
            (int)$conversation['id']
        );

        $reply->text(
            $message['phone'],
            $check['message']
        );

        return true;
    }
}

                $nextHandler->ask(

                    $reply,

                    $message['phone'],

                    $latestData

                );




                Logger::write(

                    'workflow_trace',

                    [

                        'step'=>'NEXT_STEP_SENT',

                        'next'=>$next

                    ]

                );


            }






            return true;


        }

        catch (Throwable $e) {


            Logger::write(

                'workflow_error',

                [

                    'step'=>'EXCEPTION',

                    'message'=>$e->getMessage(),

                    'file'=>$e->getFile(),

                    'line'=>$e->getLine(),

                    'trace'=>$e->getTraceAsString()

                ]

            );





            /*
            |--------------------------------------------------------------------------
            | Safe Error Reply
            |--------------------------------------------------------------------------
            */


            try {


                if (

                    !empty($message['phone'])

                ) {


                    $reply->text(

                        $message['phone'],

                        "⚠️ Something went wrong while processing your request.\n\n".

                        "Please try again."

                    );


                }


            }

            catch(Throwable $replyError) {



                Logger::write(

                    'workflow_error',

                    [

                        'step'=>'ERROR_REPLY_FAILED',

                        'message'=>$replyError->getMessage()

                    ]

                );


            }




            return true;


        }


    }


}