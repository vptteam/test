<?php

declare(strict_types=1);

namespace Services\Escrow;

use Core\Logger;
use Throwable;

use Modules\Escrow\Models\Escrow;
use Models\BotNotification;

class EscrowCompletionService
{

    protected Escrow $escrow;

    protected BotNotification $notification;

    public function __construct()
    {

        $this->escrow = new Escrow();

        $this->notification = new BotNotification();

    }

    /**
     * ---------------------------------------------------------
     * Complete Escrow
     * ---------------------------------------------------------
     */
    public function complete(

        string $reference,

        string $completedBy = 'Telegram Admin'

    ): bool
    {

        try {

            Logger::write(

                'escrow_completion',

                [

                    'step' => 'START',

                    'reference' => $reference,

                    'completed_by' => $completedBy

                ]

            );

            /*
            |--------------------------------------------------------------------------
            | Find Escrow
            |--------------------------------------------------------------------------
            */

            $escrow =

                $this->escrow->findByReference(

                    $reference

                );

            if (!$escrow) {

                Logger::write(

                    'escrow_completion',

                    [

                        'step' => 'NOT_FOUND',

                        'reference' => $reference

                    ]

                );

                return false;

            }

            /*
            |--------------------------------------------------------------------------
            | Prevent Duplicate Completion
            |--------------------------------------------------------------------------
            */

            if (

                ($escrow['status'] ?? '') === 'completed'

            ) {

                Logger::write(

                    'escrow_completion',

                    [

                        'step' => 'ALREADY_COMPLETED',

                        'reference' => $reference

                    ]

                );

                return true;

            }

            /*
            |--------------------------------------------------------------------------
            | Escrow Must Be Awaiting Payout
            |--------------------------------------------------------------------------
            */

            if (!in_array(
                ($escrow['status'] ?? ''),
                ['buyer_confirmed', 'awaiting_payout'],
                true
            )) {

                Logger::write(

                    'escrow_completion',

                    [

                        'step' => 'INVALID_STATUS',

                        'status' =>

                            $escrow['status']

                    ]

                );

                return false;

            }

            /*
            |--------------------------------------------------------------------------
            | Update Escrow
            |--------------------------------------------------------------------------
            */

            $updated =

                $this->escrow->update(

                    (int)$escrow['id'],

                    [

                        'status' => 'completed',

                        'completed_at' =>

                            date('Y-m-d H:i:s'),

                        'completed_by' =>

                            $completedBy,

                        'completed_source' =>

                            'telegram'

                    ]

                );

            if (!$updated) {

                return false;

            }

                        /*
            |--------------------------------------------------------------------------
            | Notify Seller
            |--------------------------------------------------------------------------
            */

            if (

                !$this->notification->exists(

                    (int)$escrow['seller_id'],

                    $reference

                )

            ) {

                $this->notification->create(

                    (int)$escrow['seller_id'],

                    'escrow_completed',

                    'Escrow Payment Completed',

                    "🎉 Your escrow payment has been completed.\n\n"

                    .

                    "Reference:\n"

                    .

                    "{$reference}\n\n"

                    .

                    "The administrator has confirmed that payment has been made to your registered bank account.\n\n"

                    .

                    "Thank you for using SENDAM Escrow.",

                    $reference

                );

            }

            /*
            |--------------------------------------------------------------------------
            | Notify Buyer
            |--------------------------------------------------------------------------
            */

            if (

                !$this->notification->exists(

                    (int)$escrow['buyer_id'],

                    $reference

                )

            ) {

                $this->notification->create(

                    (int)$escrow['buyer_id'],

                    'escrow_completed',

                    'Escrow Closed',

                    "✅ Your escrow transaction has been completed successfully.\n\n"

                    .

                    "Reference:\n"

                    .

                    "{$reference}\n\n"

                    .

                    "The seller has now been paid.\n\n"

                    .

                    "Thank you for choosing SENDAM Escrow.",

                    $reference

                );

            }

            /*
            |--------------------------------------------------------------------------
            | Success Log
            |--------------------------------------------------------------------------
            */

            Logger::write(

                'escrow_completion',

                [

                    'step' => 'COMPLETED',

                    'escrow_id' => $escrow['id'],

                    'reference' => $reference,

                    'completed_by' => $completedBy

                ]

            );

            return true;

        }

        catch (Throwable $e) {

            Logger::write(

                'escrow_completion_error',

                [

                    'message' => $e->getMessage(),

                    'file' => $e->getFile(),

                    'line' => $e->getLine(),

                    'reference' => $reference

                ]

            );

            return false;

        }

    }

}