<?php

declare(strict_types=1);

namespace Controllers;

use Core\Logger;
use Models\Escrow;
use Services\Escrow\EscrowService;
use Throwable;

class EscrowController
{
    protected Escrow $escrow;

    protected EscrowService $service;

    public function __construct()
    {
        $this->escrow = new Escrow();

        $this->service = new EscrowService();
    }

    /*
    |--------------------------------------------------------------------------
    | Buyer starts escrow
    |--------------------------------------------------------------------------
    */

    public function create(
        int $listingId,
        int $buyerId
    ): ?array {

        try {

            return $this->service->create(
                $listingId,
                $buyerId
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_create_error',
                [
                    'message'=>$e->getMessage(),
                    'line'=>$e->getLine(),
                    'file'=>$e->getFile()
                ]
            );

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Buyer paid
    |--------------------------------------------------------------------------
    */

    public function buyerPaid(
        string $escrowNumber
    ): bool {

        return $this->service->buyerPaid(
            $escrowNumber
        );

    }

    /*
    |--------------------------------------------------------------------------
    | Buyer confirms item received
    |--------------------------------------------------------------------------
    */

    public function buyerConfirmed(
        string $escrowNumber
    ): bool {

        return $this->service->buyerConfirmed(
            $escrowNumber
        );

    }

    /*
    |--------------------------------------------------------------------------
    | Seller confirms delivery
    |--------------------------------------------------------------------------
    */

    public function sellerConfirmed(
        string $escrowNumber
    ): bool {

        return $this->service->sellerConfirmed(
            $escrowNumber
        );

    }

    /*
    |--------------------------------------------------------------------------
    | Release money
    |--------------------------------------------------------------------------
    */

    public function release(
        string $escrowNumber
    ): bool {

        return $this->service->release(
            $escrowNumber
        );

    }

    /*
    |--------------------------------------------------------------------------
    | Cancel
    |--------------------------------------------------------------------------
    */

    public function cancel(
        string $escrowNumber
    ): bool {

        return $this->service->cancel(
            $escrowNumber
        );

    }

    /*
    |--------------------------------------------------------------------------
    | View
    |--------------------------------------------------------------------------
    */

    public function find(
        string $escrowNumber
    ): ?array {

        return $this->escrow->findByNumber(
            $escrowNumber
        );

    }

    /*
    |--------------------------------------------------------------------------
    | User history
    |--------------------------------------------------------------------------
    */

    public function history(
        int $userId
    ): array {

        return $this->escrow->history(
            $userId
        );

    }
}