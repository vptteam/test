<?php

declare(strict_types=1);

namespace Services\Escrow;

use Core\Database;
use Core\Logger;
use Models\Escrow;
use Services\Payments\PaystackGateway;
use Throwable;

class EscrowService
{
    protected Database $db;

    protected Escrow $escrow;

    protected PaystackGateway $paystack;


    public function __construct()
    {
        $this->db = Database::getInstance();

        $this->escrow = new Escrow();

        $this->paystack = new PaystackGateway();
    }


    /*
    |--------------------------------------------------------------------------
    | SETTINGS
    |--------------------------------------------------------------------------
    */

    protected function setting(
        string $key,
        mixed $default = null
    ): mixed {

        try {

            $stmt = $this->db
                ->connection()
                ->prepare(
                    "
                    SELECT `value`
                    FROM settings
                    WHERE `key` = ?
                    LIMIT 1
                    "
                );

            $stmt->execute([
                $key
            ]);

            $value = $stmt->fetchColumn();

            if ($value === false) {
                return $default;
            }

            return $value;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_settings_error',
                [
                    'key'     => $key,
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine(),
                    'file'    => $e->getFile()
                ]
            );

            return $default;
        }
    }


    protected function settingBool(
        string $key,
        bool $default = false
    ): bool {

        $value = $this->setting(
            $key,
            $default ? '1' : '0'
        );

        return in_array(
            strtolower(trim((string)$value)),
            [
                '1',
                'true',
                'yes',
                'on'
            ],
            true
        );
    }


    protected function settingFloat(
        string $key,
        float $default = 0.0
    ): float {

        $value = $this->setting(
            $key,
            $default
        );

        return is_numeric($value)
            ? (float)$value
            : $default;
    }


    /*
    |--------------------------------------------------------------------------
    | ESCROW FEE
    |--------------------------------------------------------------------------
    */

    public function calculateEscrowFee(
        float $amount
    ): float {

        if ($amount <= 0) {
            return 0.0;
        }

        if (!$this->settingBool(
            'escrow_fee_enabled',
            true
        )) {
            return 0.0;
        }


        $type = strtolower(
            trim(
                (string)$this->setting(
                    'escrow_fee_type',
                    'percentage'
                )
            )
        );


        $percentage =
            $this->settingFloat(
                'escrow_fee_percentage',
                0.0
            );


        $fixed =
            $this->settingFloat(
                'escrow_fee_fixed',
                0.0
            );


        $fee = 0.0;


        if (
            $type === 'percentage'
            ||
            $type === 'percentage_plus_fixed'
        ) {

            $fee +=
                ($amount * $percentage) / 100;
        }


        if (
            $type === 'fixed'
            ||
            $type === 'percentage_plus_fixed'
        ) {

            $fee += $fixed;
        }


        return round(
            max(0, $fee),
            2
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

        if ($amount <= 0) {
            return 0.0;
        }


        if (!$this->settingBool(
            'escrow_paystack_fee_enabled',
            true
        )) {
            return 0.0;
        }


        $type = strtolower(
            trim(
                (string)$this->setting(
                    'escrow_paystack_fee_type',
                    'percentage_plus_fixed'
                )
            )
        );


        $percentage =
            $this->settingFloat(
                'escrow_paystack_fee_percentage',
                0.0
            );


        $fixed =
            $this->settingFloat(
                'escrow_paystack_fee_fixed',
                0.0
            );


        $fee = 0.0;


        if (
            $type === 'percentage'
            ||
            $type === 'percentage_plus_fixed'
        ) {

            $fee +=
                ($amount * $percentage) / 100;
        }


        if (
            $type === 'fixed'
            ||
            $type === 'percentage_plus_fixed'
        ) {

            $fee += $fixed;
        }


        return round(
            max(0, $fee),
            2
        );
    }


    /*
    |--------------------------------------------------------------------------
    | TOTAL BUYER PAYMENT
    |--------------------------------------------------------------------------
    */

    public function calculateBuyerTotal(
        float $amount
    ): array {

        $escrowFee =
            $this->calculateEscrowFee(
                $amount
            );


        $paystackFee =
            $this->calculatePaystackFee(
                $amount
            );


        $escrowPayer =
            strtolower(
                trim(
                    (string)$this->setting(
                        'escrow_fee_payer',
                        'buyer'
                    )
                )
            );


        $paystackPayer =
            strtolower(
                trim(
                    (string)$this->setting(
                        'escrow_paystack_fee_payer',
                        'buyer'
                    )
                )
            );


        $buyerTotal = $amount;


        if ($escrowPayer === 'buyer') {
            $buyerTotal += $escrowFee;
        }


        if ($paystackPayer === 'buyer') {
            $buyerTotal += $paystackFee;
        }


        return [
            'amount'        => round($amount, 2),
            'escrow_fee'    => round($escrowFee, 2),
            'paystack_fee'  => round($paystackFee, 2),
            'buyer_total'   => round($buyerTotal, 2),
            'escrow_payer'  => $escrowPayer,
            'paystack_payer'=> $paystackPayer
        ];
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
                'escrow_service',
                [
                    'step'       => 'CREATE_START',
                    'listing_id' => $listingId,
                    'buyer_id'   => $buyerId
                ]
            );


            if ($listingId <= 0) {

                Logger::write(
                    'escrow_service_error',
                    [
                        'step' => 'INVALID_LISTING_ID',
                        'listing_id' => $listingId
                    ]
                );

                return null;
            }


            if ($buyerId <= 0) {

                Logger::write(
                    'escrow_service_error',
                    [
                        'step' => 'INVALID_BUYER_ID',
                        'buyer_id' => $buyerId
                    ]
                );

                return null;
            }


            /*
            |--------------------------------------------------------------------------
            | Delegate actual escrow creation to model
            |--------------------------------------------------------------------------
            |
            | We deliberately do not invent a model method here.
            | The existing Escrow model remains responsible for the
            | database-specific creation operation.
            |
            */

            if (!method_exists(
                $this->escrow,
                'create'
            )) {

                Logger::write(
                    'escrow_service_error',
                    [
                        'step' =>
                            'ESCROW_MODEL_CREATE_METHOD_MISSING'
                    ]
                );

                return null;
            }


            $result = $this->escrow->create(
                $listingId,
                $buyerId
            );


            Logger::write(
                'escrow_service',
                [
                    'step'   => 'CREATE_RESULT',
                    'result' => $result
                ]
            );


            return is_array($result)
                ? $result
                : null;


        } catch (Throwable $e) {

            Logger::write(
                'escrow_service_error',
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

            $escrowNumber =
                trim($escrowNumber);


            if ($escrowNumber === '') {

                return [
                    'success' => false,
                    'message' => 'Escrow number is required.'
                ];
            }


            $escrow =
                $this->escrow->findByNumber(
                    $escrowNumber
                );


            if (!$escrow) {

                return [
                    'success' => false,
                    'message' => 'Escrow transaction not found.'
                ];
            }


            $amount =
                (float)(
                    $escrow['amount']
                    ?? 0
                );


            if ($amount <= 0) {

                return [
                    'success' => false,
                    'message' => 'Invalid escrow amount.'
                ];
            }


            $fees =
                $this->calculateBuyerTotal(
                    $amount
                );


            $reference =
                $escrow['payment_reference']
                ?? (
                    'ESC-' .
                    strtoupper(
                        $escrowNumber
                    ) .
                    '-' .
                    time()
                );


            $metadata = [

                'type' =>
                    'escrow_payment',

                'escrow_number' =>
                    $escrowNumber,

                'escrow_id' =>
                    $escrow['id']
                    ?? null,

                'buyer_id' =>
                    $escrow['buyer_id']
                    ?? null,

                'seller_id' =>
                    $escrow['seller_id']
                    ?? null,

                'escrow_amount' =>
                    $fees['amount'],

                'escrow_fee' =>
                    $fees['escrow_fee'],

                'paystack_fee' =>
                    $fees['paystack_fee']
            ];


            Logger::write(
                'escrow_service',
                [
                    'step'         => 'PAYMENT_INITIALIZE',
                    'escrow_number'=> $escrowNumber,
                    'reference'    => $reference,
                    'fees'         => $fees
                ]
            );


            $payment =
                $this->paystack->initialize(
                    (int)round(
                        $fees['buyer_total']
                    ),
                    $email,
                    $reference,
                    $callback,
                    $metadata
                );


            if (
                !($payment['success'] ?? false)
            ) {

                return $payment;
            }


            return [
                'success' =>
                    true,

                'escrow' =>
                    $escrow,

                'amount' =>
                    $fees['amount'],

                'escrow_fee' =>
                    $fees['escrow_fee'],

                'paystack_fee' =>
                    $fees['paystack_fee'],

                'total' =>
                    $fees['buyer_total'],

                'authorization_url' =>
                    $payment['authorization_url']
                    ?? null,

                'access_code' =>
                    $payment['access_code']
                    ?? null,

                'reference' =>
                    $payment['reference']
                    ?? $reference,

                'raw' =>
                    $payment['raw']
                    ?? null
            ];


        } catch (Throwable $e) {

            Logger::write(
                'escrow_payment_error',
                [
                    'step'    => 'INITIALIZE_EXCEPTION',
                    'escrow'  => $escrowNumber,
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine(),
                    'file'    => $e->getFile()
                ]
            );


            return [
                'success' => false,
                'message' => 'Unable to initialize escrow payment.'
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

            $result =
                $this->paystack->verify(
                    $reference
                );


            Logger::write(
                'escrow_payment',
                [
                    'step'      => 'VERIFY_RESULT',
                    'reference' => $reference,
                    'success'   =>
                        $result['success']
                        ?? false
                ]
            );


            return $result;


        } catch (Throwable $e) {

            Logger::write(
                'escrow_payment_error',
                [
                    'step'      => 'VERIFY_EXCEPTION',
                    'reference' => $reference,
                    'message'   => $e->getMessage(),
                    'line'      => $e->getLine()
                ]
            );


            return [
                'success' => false,
                'message' => 'Payment verification failed.'
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

            $escrowNumber =
                trim($escrowNumber);


            if ($escrowNumber === '') {
                return false;
            }


            if (!method_exists(
                $this->escrow,
                'buyerPaid'
            )) {

                Logger::write(
                    'escrow_service_error',
                    [
                        'step' =>
                            'BUYER_PAID_METHOD_MISSING'
                    ]
                );

                return false;
            }


            return (bool)$this->escrow->buyerPaid(
                $escrowNumber
            );


        } catch (Throwable $e) {

            Logger::write(
                'escrow_service_error',
                [
                    'step'    => 'BUYER_PAID_EXCEPTION',
                    'escrow'  => $escrowNumber,
                    'message' => $e->getMessage()
                ]
            );

            return false;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | BUYER CONFIRMATION
    |--------------------------------------------------------------------------
    */

    public function buyerConfirmed(
        string $escrowNumber
    ): bool {

        try {

            if (
                method_exists(
                    $this->escrow,
                    'buyerConfirmed'
                )
            ) {

                return (bool)$this->escrow->buyerConfirmed(
                    $escrowNumber
                );
            }


            if (
                method_exists(
                    $this->escrow,
                    'buyerConfirm'
                )
            ) {

                return (bool)$this->escrow->buyerConfirm(
                    $escrowNumber
                );
            }


            Logger::write(
                'escrow_service_error',
                [
                    'step' =>
                        'BUYER_CONFIRM_METHOD_MISSING'
                ]
            );


            return false;


        } catch (Throwable $e) {

            Logger::write(
                'escrow_service_error',
                [
                    'step'    => 'BUYER_CONFIRM_EXCEPTION',
                    'escrow'  => $escrowNumber,
                    'message' => $e->getMessage()
                ]
            );

            return false;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SELLER CONFIRMATION
    |--------------------------------------------------------------------------
    */

    public function sellerConfirmed(
        string $escrowNumber
    ): bool {

        try {

            if (
                method_exists(
                    $this->escrow,
                    'sellerConfirmed'
                )
            ) {

                return (bool)$this->escrow->sellerConfirmed(
                    $escrowNumber
                );
            }


            if (
                method_exists(
                    $this->escrow,
                    'sellerConfirm'
                )
            ) {

                return (bool)$this->escrow->sellerConfirm(
                    $escrowNumber
                );
            }


            Logger::write(
                'escrow_service_error',
                [
                    'step' =>
                        'SELLER_CONFIRM_METHOD_MISSING'
                ]
            );


            return false;


        } catch (Throwable $e) {

            Logger::write(
                'escrow_service_error',
                [
                    'step'    => 'SELLER_CONFIRM_EXCEPTION',
                    'escrow'  => $escrowNumber,
                    'message' => $e->getMessage()
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

            if (
                method_exists(
                    $this->escrow,
                    'release'
                )
            ) {

                return (bool)$this->escrow->release(
                    $escrowNumber
                );
            }


            Logger::write(
                'escrow_service_error',
                [
                    'step' =>
                        'RELEASE_METHOD_MISSING'
                ]
            );


            return false;


        } catch (Throwable $e) {

            Logger::write(
                'escrow_service_error',
                [
                    'step'    => 'RELEASE_EXCEPTION',
                    'escrow'  => $escrowNumber,
                    'message' => $e->getMessage()
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

            if (
                method_exists(
                    $this->escrow,
                    'cancel'
                )
            ) {

                return (bool)$this->escrow->cancel(
                    $escrowNumber
                );
            }


            Logger::write(
                'escrow_service_error',
                [
                    'step' =>
                        'CANCEL_METHOD_MISSING'
                ]
            );


            return false;


        } catch (Throwable $e) {

            Logger::write(
                'escrow_service_error',
                [
                    'step'    => 'CANCEL_EXCEPTION',
                    'escrow'  => $escrowNumber,
                    'message' => $e->getMessage()
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

            if (!$this->settingBool(
                'escrow_require_admin_approval',
                true
            )) {

                Logger::write(
                    'escrow_service',
                    [
                        'step' =>
                            'REFUND_ADMIN_APPROVAL_DISABLED'
                    ]
                );
            }


            if (
                method_exists(
                    $this->escrow,
                    'refund'
                )
            ) {

                return (bool)$this->escrow->refund(
                    $escrowNumber
                );
            }


            Logger::write(
                'escrow_service_error',
                [
                    'step' =>
                        'REFUND_METHOD_MISSING'
                ]
            );


            return false;


        } catch (Throwable $e) {

            Logger::write(
                'escrow_service_error',
                [
                    'step'    => 'REFUND_EXCEPTION',
                    'escrow'  => $escrowNumber,
                    'message' => $e->getMessage()
                ]
            );

            return false;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | FIND
    |--------------------------------------------------------------------------
    */

    public function find(
        string $escrowNumber
    ): ?array {

        try {

            return $this->escrow->findByNumber(
                trim($escrowNumber)
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_service_error',
                [
                    'step'    => 'FIND_EXCEPTION',
                    'escrow'  => $escrowNumber,
                    'message' => $e->getMessage()
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

            return $this->escrow->history(
                $userId
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_service_error',
                [
                    'step'    => 'HISTORY_EXCEPTION',
                    'user_id' => $userId,
                    'message' => $e->getMessage()
                ]
            );

            return [];
        }
    }
}