<?php

declare(strict_types=1);

namespace Modules;

use Core\Logger;
use Core\ReplyInterface;
use Core\WorkflowExecutor;
use Models\Conversation;
use Throwable;

class BotWorkflowManager
{
    /**
     * --------------------------------------------------------------------------
     * Handle Active Workflow
     * --------------------------------------------------------------------------
     *
     * Checks whether the user has an active conversation and, if so,
     * delegates execution to WorkflowExecutor.
     *
     * Returns:
     *   true  = workflow handled
     *   false = no active workflow
     */
    public function handle(
        array $user,
        array $message,
        ReplyInterface $reply
    ): bool {

        try {

            $conversationModel = new Conversation();

            $conversation = $conversationModel->active(
                (int)($user['id'] ?? 0)
            );

            if (!$conversation) {

                Logger::write(
                    'workflow_manager',
                    [
                        'step'    => 'NO_ACTIVE_WORKFLOW',
                        'user_id' => $user['id'] ?? null
                    ]
                );

                return false;

            }

            Logger::write(
                'workflow_manager',
                [
                    'step'         => 'ACTIVE_WORKFLOW_FOUND',
                    'user_id'      => $user['id'],
                    'conversation' => $conversation
                ]
            );

            $executor = new WorkflowExecutor();

            return $executor->run(
                $user,
                $message,
                $reply
            );

        }

        catch (Throwable $e) {

            Logger::write(
                'workflow_manager_error',
                [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'trace'   => $e->getTraceAsString()
                ]
            );

            try {

                $reply->text(
                    $message['phone'] ?? '',
                    "⚠️ Unable to process your request."
                );

            } catch (Throwable $ignore) {
                // Ignore reply failures
            }

            return true;

        }

    }


    /**
     * --------------------------------------------------------------------------
     * Cancel Active Workflow
     * --------------------------------------------------------------------------
     */
    public function cancel(
        int $userId
    ): void {

        try {

            (new Conversation())->cancel($userId);

            Logger::write(
                'workflow_manager',
                [
                    'step'    => 'WORKFLOW_CANCELLED',
                    'user_id' => $userId
                ]
            );

        }

        catch (Throwable $e) {

            Logger::write(
                'workflow_manager_error',
                [
                    'step'    => 'CANCEL_FAILED',
                    'user_id' => $userId,
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine()
                ]
            );

        }

    }

}