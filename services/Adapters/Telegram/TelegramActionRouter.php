<?php

declare(strict_types=1);

namespace Services\Telegram;

use Core\Logger;
use Services\Escrow\EscrowCompletionService;
use Throwable;

class TelegramActionRouter
{

    /**
     * ---------------------------------------------------------
     * Dispatch Action
     * ---------------------------------------------------------
     */
    public function dispatch(

        string $module,

        string $action,

        string $reference,

        array $callback

    ): array
    {

        try {

            switch ($module) {

                /*
                |--------------------------------------------------------------------------
                | Escrow
                |--------------------------------------------------------------------------
                */

                case 'escrow':

                    return $this->escrow(

                        $action,

                        $reference,

                        $callback

                    );

                /*
                |--------------------------------------------------------------------------
                | Withdrawal
                |--------------------------------------------------------------------------
                */

                case 'withdrawal':

                    return $this->withdrawal(

                        $action,

                        $reference,

                        $callback

                    );

                default:

                    return [

    'success' => false,

    'message' => 'Unknown module.'

];

            }

        }

        catch (Throwable $e) {

            Logger::write(

                'telegram_router_error',

                [

                    'message' => $e->getMessage(),

                    'module' => $module,

                    'action' => $action,

                    'reference' => $reference

                ]

            );

        return [

    'success' => false,

    'message' => 'An unexpected error occurred.'

];

        }

    }

        /**
     * ---------------------------------------------------------
     * Escrow Actions
     * ---------------------------------------------------------
     */
    protected function escrow(

        string $action,

        string $reference,

        array $callback

    ): array
    {

        switch ($action) {

            /*
            |--------------------------------------------------------------------------
            | Admin has manually paid seller
            |--------------------------------------------------------------------------
            */

            case 'paid':

    $service = new EscrowCompletionService();

    $success = $service->complete(

        $reference,

        (string)($callback['from']['first_name'] ?? 'Telegram Admin')

    );

    if (!$success) {

        return [

            'success' => false,

            'message' =>

                "❌ Unable to complete escrow.\n\nReference: {$reference}"

        ];

    }

    return [

        'success' => true,

        'message' =>

            "✅ ESCROW CLOSED\n\n"

            .

            "Reference: {$reference}\n\n"

            .

            "Seller has been paid manually."

    ];

            /*
            |--------------------------------------------------------------------------
            | View Details
            |--------------------------------------------------------------------------
            */

            case 'details':

                Logger::write(

                    'telegram_router',

                    [

                        'module' => 'escrow',

                        'action' => 'details',

                        'reference' => $reference

                    ]

                );

                return [

    'success' => true,

    'message' =>

        "📄 Escrow Details\n\nReference: {$reference}"

];

        }

        return [

    'success' => false,

    'message' => 'An unexpected error occurred.'

];

    }

    /**
     * ---------------------------------------------------------
     * Withdrawal Actions
     * ---------------------------------------------------------
     */
    protected function withdrawal(

        string $action,

        string $reference,

        array $callback

    ): array
    {

        switch ($action) {

            case 'approve':

                Logger::write(

                    'telegram_router',

                    [

                        'module' => 'withdrawal',

                        'action' => 'approve',

                        'reference' => $reference

                    ]

                );

                /*
                 * WithdrawalService will be added later
                 */

                return [

    'success' => true,

    'message' =>

        "✅ Withdrawal approved."

];

            case 'reject':

                Logger::write(

                    'telegram_router',

                    [

                        'module' => 'withdrawal',

                        'action' => 'reject',

                        'reference' => $reference

                    ]

                );

            return [

    'success' => true,

    'message' =>

        "❌ Withdrawal rejected."

];

        }

        return [

    'success' => false,

    'message' => 'Unknown action.'

];

    }

}