<?php

declare(strict_types=1);

namespace Modules;

use Core\Logger;
use Core\ReplyFactory;

use Models\Conversation;

use Modules\Escrow\Services\BotUserService;
use Modules\Escrow\Services\BotGreetingService;
use Modules\Escrow\Services\BotReferenceService;
use Modules\Escrow\Services\BotNotificationService;
use Modules\Escrow\Services\BotPaymentSyncService;
use Modules\Escrow\Services\BotExceptionHandler;

class BotEngine
{

    protected BotCommandRouter $commandRouter;

    protected BotWorkflowManager $workflowManager;

    protected BotUserService $userService;

    protected BotGreetingService $greetingService;

    protected BotReferenceService $referenceService;

    protected BotNotificationService $notificationService;

    protected BotPaymentSyncService $paymentService;

    protected BotExceptionHandler $exceptionHandler;


    /**
     * --------------------------------------------------------------------------
     * Constructor
     * --------------------------------------------------------------------------
     */
    public function __construct()
    {

        $this->commandRouter = new BotCommandRouter();

        $this->workflowManager = new BotWorkflowManager();

        $this->userService = new BotUserService();

        $this->greetingService = new BotGreetingService();

        $this->referenceService = new BotReferenceService();

        $this->notificationService = new BotNotificationService();

        $this->paymentService = new BotPaymentSyncService();

        $this->exceptionHandler = new BotExceptionHandler();

    }



    /**
     * --------------------------------------------------------------------------
     * Main Bot Processor
     * --------------------------------------------------------------------------
     */
    public function process(
        array $user,
        array $message
    ): void {

        $reply = null;

        try {

            Logger::write(
                'bot_engine',
                [
                    'step'    => 'START',
                    'message' => $message
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Validate Request
            |--------------------------------------------------------------------------
            */

            $this->validateMessage(
                $message
            );

            /*
            |--------------------------------------------------------------------------
            | Synchronise User
            |--------------------------------------------------------------------------
            */
            Logger::write('bot_engine', [
    'step' => 'USER_SYNC_START'
]);

            $user = $this->userService->sync(
                $user,
                $message
            );

Logger::write('bot_engine', [
    'step'    => 'USER_SYNC_COMPLETE',
    'user_id' => $user['id'] ?? null
]);

            if (empty($user['id'])) {
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Reply Driver
            |--------------------------------------------------------------------------
            */
Logger::write('bot_engine', [
    'step' => 'REPLY_FACTORY_START'
]);

            $reply = ReplyFactory::make(
                $message['platform']
            );

Logger::write('bot_engine', [
    'step' => 'REPLY_FACTORY_COMPLETE'
]);

            /*
            |--------------------------------------------------------------------------
            | Pending Notifications
            |--------------------------------------------------------------------------
            */
            
            Logger::write('bot_engine', [
    'step' => 'NOTIFICATION_SERVICE_START'
]);

            $this->notificationService->send(
                (int) $user['id'],
                $reply,
                $message['phone']
            );
            
            Logger::write('bot_engine', [
    'step' => 'NOTIFICATION_SERVICE_COMPLETE'
]);

            /*
            |--------------------------------------------------------------------------
            | Payment Synchronisation
            |--------------------------------------------------------------------------
            */
Logger::write('bot_engine', [
    'step' => 'PAYMENT_SYNC_START'
]);

            $this->paymentService->sync(
                (int) $user['id'],
                $reply,
                $message['phone']
            );

Logger::write('bot_engine', [
    'step' => 'PAYMENT_SYNC_COMPLETE'
]);

            /*
            |--------------------------------------------------------------------------
            | Newly Generated Notifications
            |--------------------------------------------------------------------------
            */
Logger::write('bot_engine', [
    'step' => 'NOTIFICATION_SERVICE_START'
]);

            $this->notificationService->send(
                (int) $user['id'],
                $reply,
                $message['phone']
            );

Logger::write('bot_engine', [
    'step' => 'NOTIFICATION_SERVICE_COMPLETE'
]);

                        /*
            |--------------------------------------------------------------------------
            | Normalize Incoming Message
            |--------------------------------------------------------------------------
            */

            $text = strtolower(
                trim(
                    $message['text'] ?? ''
                )
            );

            Logger::write(
                'bot_engine',
                [
                    'step' => 'TEXT_RECEIVED',
                    'text' => $text
                ]
            );



            /*
            |--------------------------------------------------------------------------
            | Cancel Current Workflow
            |--------------------------------------------------------------------------
            */
Logger::write('bot_engine', [
    'step' => 'CHECK_CANCEL_WORKFLOW'
]);

            if (
                $this->cancelWorkflow(
                    $text,
                    $user,
                    $reply,
                    $message
                )
            ) {

Logger::write('bot_engine', [
        'step' => 'WORKFLOW_CANCELLED'
    ]);
                return;
            }



            /*
            |--------------------------------------------------------------------------
            | Greetings
            |--------------------------------------------------------------------------
            */

   Logger::write('bot_engine', [
    'step' => 'GREETING_SERVICE_START'
]);

if (
    $this->greetingService->handle(
        $text,
        $user,
        $message,
        $reply
    )
) {

    Logger::write('bot_engine', [
        'step' => 'GREETING_HANDLED'
    ]);

    return;
}

Logger::write('bot_engine', [
    'step' => 'GREETING_SKIPPED'
]);


            /*
            |--------------------------------------------------------------------------
            | Command Router
            |--------------------------------------------------------------------------
            */

            Logger::write('bot_engine', [
    'step' => 'COMMAND_ROUTER_START'
]);

if (
    $this->commandRouter->handle(
        $text,
        $user,
        $message,
        $reply
    )
) {

    Logger::write('bot_engine', [
        'step' => 'COMMAND_HANDLED'
    ]);

    return;
}

Logger::write('bot_engine', [
    'step' => 'COMMAND_NOT_HANDLED'
]);



            /*
            |--------------------------------------------------------------------------
            | Listing Reference Lookup
            |--------------------------------------------------------------------------
            */

            Logger::write('bot_engine', [
    'step' => 'REFERENCE_SERVICE_START'
]);


Logger::write(
    'reference_service',
    [
        'step' => 'START',
        'text' => $message['text'] ?? null,
        'user_id' => $user['id'] ?? null
    ]
);

if (
    $this->referenceService->handle(
    $user,
    $message,
    $reply
)
) {

    Logger::write('bot_engine', [
        'step' => 'REFERENCE_HANDLED'
    ]);

    return;
}

Logger::write('bot_engine', [
    'step' => 'REFERENCE_NOT_FOUND'
]);



            /*
            |--------------------------------------------------------------------------
            | Active Workflow
            |--------------------------------------------------------------------------
            */

            Logger::write('bot_engine', [
    'step' => 'WORKFLOW_MANAGER_START'
]);

if (
    $this->workflowManager->handle(
        $user,
        $message,
        $reply
    )
) {

    Logger::write('bot_engine', [
        'step' => 'WORKFLOW_HANDLED'
    ]);

    return;
}

Logger::write('bot_engine', [
    'step' => 'NO_ACTIVE_WORKFLOW'
]);



            /*
            |--------------------------------------------------------------------------
            | Default Reply
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'bot_engine',
                [
                    'step' => 'NO_MATCH_FOUND',
                    'text' => $text
                ]
            );

            $reply->text(
                $message['phone'],
                "👋 Wrong code written.\n\nTry again."
            );

        }

        catch (Throwable $e) {

    $this->exceptionHandler->handle(
        $e,
        $reply,
        $message['phone'] ?? null
    );

}

    }
        /**
     * --------------------------------------------------------------------------
     * Validate Incoming Message
     * --------------------------------------------------------------------------
     */
    protected function validateMessage(
        array $message
    ): void {

        if (
            empty($message['platform']) ||
            empty($message['phone'])
        ) {

            Logger::write(
                'bot_engine_error',
                [
                    'step'    => 'INVALID_MESSAGE',
                    'message' => $message
                ]
            );

            throw new \RuntimeException(
                'Invalid incoming message.'
            );

        }

    }



    /**
     * --------------------------------------------------------------------------
     * Cancel Active Workflow
     * --------------------------------------------------------------------------
     */
    protected function cancelWorkflow(
        string $text,
        array $user,
        $reply,
        array $message
    ): bool {

        if (
            !in_array(
                $text,
                [
                    'cancel',
                    '/cancel',
                    'stop',
                    'quit'
                ],
                true
            )
        ) {
            return false;
        }

        (new Conversation())->cancel(
            (int) $user['id']
        );

        Logger::write(
            'bot_engine',
            [
                'step'    => 'WORKFLOW_CANCELLED',
                'user_id' => $user['id']
            ]
        );

        $reply->text(
            $message['phone'],
            "✅ Current operation cancelled."
        );

        return true;

    }

}