<?php

declare(strict_types=1);

namespace Modules\Escrow\Services;

use Core\Logger;
use Services\Escrow\EscrowService as CoreEscrowService;
use Throwable;

class EscrowService
{
    protected CoreEscrowService $core;


    public function __construct()
    {
        $this->core = new CoreEscrowService();

        Logger::write(
            'module_escrow_service',
            [
                'step' => 'CONSTRUCTOR'
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CREATE ESCROW
    |--------------------------------------------------------------------------
    */

    public function create(
        int $listingId,
        int $buyerId
    ): ?array {

        try {

            Logger::write(
                'module_escrow_service',
                [
                    'step'       => 'CREATE',
                    'listing_id' => $listingId,
                    'buyer_id'   => $buyerId
                ]
            );


            return $this->core->create(
                $listingId,
                $buyerId
            );


        } catch (Throwable $e) {

            Logger::write(
                'module_escrow_service_error',
                [
                    'step'    => 'CREATE_EXCEPTION',
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine(),
                    'file'    => $e->getFile()
                ]
            );

            return null;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | INITIALIZE PAYMENT
    |--------------------------------------------------------------------------
    */

    public function initializePayment(
        string $escrowNumber,
        string $email,
        string $callback
    ): array {

        try {

            Logger::write(
                'module_escrow_service',
                [
                    'step'         => 'INITIALIZE_PAYMENT',
                    'escrow_number'=> $escrowNumber,
                    'email'        => $email
                ]
            );


            return $this->core->initializePayment(
                $escrowNumber,
                $email,
                $callback
            );


        } catch (Throwable $e) {

            Logger::write(
                'module_escrow_service_error',
                [
                    'step'    => 'INITIALIZE_PAYMENT_EXCEPTION',
                    'escrow'  => $escrowNumber,
                    'message' => $e->getMessage()
                ]
            );


            return [
                'success' => false,
                'message' => 'Unable to initialize payment.'
            ];
        }
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFY PAYMENT
    |--------------------------------------------------------------------------
    */

    public function verifyPayment(
        string $reference
    ): array {

        try {

            Logger::write(
                'module_escrow_service',
                [
                    'step'      => 'VERIFY_PAYMENT',
                    'reference' => $reference
                ]
            );


            return $this->core->verifyPayment(
                $reference
            );


        } catch (Throwable $e) {

            Logger::write(
                'module_escrow_service_error',
                [
                    'step'    => 'VERIFY_PAYMENT_EXCEPTION',
                    'reference' => $reference,
                    'message' => $e->getMessage()
                ]
            );


            return [
                'success' => false,
                'message' => 'Unable to verify payment.'
            ];
        }
    }


    /*
    |--------------------------------------------------------------------------
    | BUYER PAID
    |--------------------------------------------------------------------------
    */

    public function buyerPaid(
        string $escrowNumber
    ): bool {

        try {

            return $this->core->buyerPaid(
                $escrowNumber
            );

        } catch (Throwable $e) {

            Logger::write(
                'module_escrow_service_error',
                [
                    'step'   => 'BUYER_PAID_EXCEPTION',
                    'escrow' => $escrowNumber,
                    'message'=> $e->getMessage()
                ]
            );

            return false;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | BUYER CONFIRMED
    |--------------------------------------------------------------------------
    */

    public function buyerConfirmed(
        string $escrowNumber
    ): bool {

        try {

            return $this->core->buyerConfirmed(
                $escrowNumber
            );

        } catch (Throwable $e) {

            Logger::write(
                'module_escrow_service_error',
                [
                    'step'   => 'BUYER_CONFIRMED_EXCEPTION',
                    'escrow' => $escrowNumber,
                    'message'=> $e->getMessage()
                ]
            );

            return false;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SELLER CONFIRMED
    |--------------------------------------------------------------------------
    */

    public function sellerConfirmed(
        string $escrowNumber
    ): bool {

        try {

            return $this->core->sellerConfirmed(
                $escrowNumber
            );

        } catch (Throwable $e) {

            Logger::write(
                'module_escrow_service_error',
                [
                    'step'   => 'SELLER_CONFIRMED_EXCEPTION',
                    'escrow' => $escrowNumber,
                    'message'=> $e->getMessage()
                ]
            );

            return false;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | RELEASE
    |--------------------------------------------------------------------------
    */

    public function release(
        string $escrowNumber
    ): bool {

        try {

            return $this->core->release(
                $escrowNumber
            );

        } catch (Throwable $e) {

            Logger::write(
                'module_escrow_service_error',
                [
                    'step'   => 'RELEASE_EXCEPTION',
                    'escrow' => $escrowNumber,
                    'message'=> $e->getMessage()
                ]
            );

            return false;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | CANCEL
    |--------------------------------------------------------------------------
    */

    public function cancel(
        string $escrowNumber
    ): bool {

        try {

            return $this->core->cancel(
                $escrowNumber
            );

        } catch (Throwable $e) {

            Logger::write(
                'module_escrow_service_error',
                [
                    'step'   => 'CANCEL_EXCEPTION',
                    'escrow' => $escrowNumber,
                    'message'=> $e->getMessage()
                ]
            );

            return false;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | REFUND
    |--------------------------------------------------------------------------
    */

    public function refund(
        string $escrowNumber
    ): bool {

        try {

            return $this->core->refund(
                $escrowNumber
            );

        } catch (Throwable $e) {

            Logger::write(
                'module_escrow_service_error',
                [
                    'step'   => 'REFUND_EXCEPTION',
                    'escrow' => $escrowNumber,
                    'message'=> $e->getMessage()
                ]
            );

            return false;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | FIND ESCROW
    |--------------------------------------------------------------------------
    */

    public function find(
        string $escrowNumber
    ): ?array {

        try {

            return $this->core->find(
                $escrowNumber
            );

        } catch (Throwable $e) {

            Logger::write(
                'module_escrow_service_error',
                [
                    'step'   => 'FIND_EXCEPTION',
                    'escrow' => $escrowNumber,
                    'message'=> $e->getMessage()
                ]
            );

            return null;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | HISTORY
    |--------------------------------------------------------------------------
    */

    public function history(
        int $userId
    ): array {

        try {

            return $this->core->history(
                $userId
            );

        } catch (Throwable $e) {

            Logger::write(
                'module_escrow_service_error',
                [
                    'step'    => 'HISTORY_EXCEPTION',
                    'user_id' => $userId,
                    'message' => $e->getMessage()
                ]
            );

            return [];
        }
    }


    /*
    |--------------------------------------------------------------------------
    | ESCROW FEE
    |--------------------------------------------------------------------------
    */

    public function calculateEscrowFee(
        float $amount
    ): float {

        return $this->core->calculateEscrowFee(
            $amount
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PAYSTACK FEE
    |--------------------------------------------------------------------------
    */

    public function calculatePaystackFee(
        float $amount
    ): float {

        return $this->core->calculatePaystackFee(
            $amount
        );
    }


    /*
    |--------------------------------------------------------------------------
    | BUYER TOTAL
    |--------------------------------------------------------------------------
    */

    public function calculateBuyerTotal(
        float $amount
    ): array {

        return $this->core->calculateBuyerTotal(
            $amount
        );
    }
}