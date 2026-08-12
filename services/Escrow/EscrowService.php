<?php

declare(strict_types=1);

namespace Services\Escrow;

use Core\Database;
use Core\Logger;
use Modules\Escrow\Models\Escrow;
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


/**
 * ---------------------------------------------------------
 * CREATE ESCROW
 * ---------------------------------------------------------
 *
 * Creates an escrow transaction from an existing listing.
 *
 * IMPORTANT:
 *
 * - $listingId must be the internal listings.id
 * - $buyerId must be the internal users.id
 * - Seller is resolved from the listing
 * - Amount is resolved from the listing
 * - The Escrow model remains responsible for the INSERT
 * - No escrow_number column is used
 * - The public escrow identifier is "reference"
 *
 * ---------------------------------------------------------
 */
public function create(
    int $listingId,
    int $buyerId
): ?array {

    try {

        Logger::write(
            'escrow_service',
            [
                'step' => 'CREATE_START',
                'listing_id' => $listingId,
                'buyer_id' => $buyerId
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Validate IDs
        |--------------------------------------------------------------------------
        */

        if ($listingId <= 0) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' => 'CREATE_INVALID_LISTING_ID',
                    'listing_id' => $listingId,
                    'buyer_id' => $buyerId
                ]
            );

            return null;
        }


        if ($buyerId <= 0) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' => 'CREATE_INVALID_BUYER_ID',
                    'listing_id' => $listingId,
                    'buyer_id' => $buyerId
                ]
            );

            return null;
        }


        /*
        |--------------------------------------------------------------------------
        | Load Listing
        |--------------------------------------------------------------------------
        */

        $listing =
            $this->listingModel->find(
                $listingId
            );


        Logger::write(
            'escrow_service',
            [
                'step' => 'CREATE_LISTING_LOOKUP',
                'listing_id' => $listingId,
                'found' => is_array($listing)
            ]
        );


        if (
            !is_array($listing)
            ||
            empty($listing)
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' => 'CREATE_LISTING_NOT_FOUND',
                    'listing_id' => $listingId,
                    'buyer_id' => $buyerId
                ]
            );

            return null;
        }


        /*
        |--------------------------------------------------------------------------
        | Resolve Seller
        |--------------------------------------------------------------------------
        */

        $sellerId =
            (int)(
                $listing['seller_id']
                ??
                $listing['user_id']
                ??
                0
            );


        if ($sellerId <= 0) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' => 'CREATE_SELLER_NOT_FOUND',
                    'listing_id' => $listingId,
                    'buyer_id' => $buyerId,
                    'listing' => $listing
                ]
            );

            return null;
        }


        /*
        |--------------------------------------------------------------------------
        | Prevent Buyer From Buying Own Listing
        |--------------------------------------------------------------------------
        */

        if ($sellerId === $buyerId) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' => 'CREATE_SELF_PURCHASE_BLOCKED',
                    'listing_id' => $listingId,
                    'buyer_id' => $buyerId,
                    'seller_id' => $sellerId
                ]
            );

            return [
                'success' => false,
                'message' =>
                    'You cannot create an escrow transaction for your own listing.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Resolve Amount
        |--------------------------------------------------------------------------
        */

        $amount =
            $listing['price']
            ??
            $listing['amount']
            ??
            $listing['selling_price']
            ??
            $listing['total_amount']
            ??
            null;


        if (
            $amount === null
            ||
            !is_numeric($amount)
            ||
            (float)$amount <= 0
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' => 'CREATE_INVALID_AMOUNT',
                    'listing_id' => $listingId,
                    'buyer_id' => $buyerId,
                    'seller_id' => $sellerId,
                    'amount' => $amount
                ]
            );

            return [
                'success' => false,
                'message' =>
                    'Listing has an invalid amount.'
            ];
        }


        $amount =
            (float)$amount;


        /*
        |--------------------------------------------------------------------------
        | Currency
        |--------------------------------------------------------------------------
        */

        $currency =
            strtoupper(
                trim(
                    (string)(
                        $listing['currency']
                        ??
                        'NGN'
                    )
                )
            );


        if ($currency === '') {

            $currency = 'NGN';
        }


        /*
        |--------------------------------------------------------------------------
        | Buyer Phone
        |--------------------------------------------------------------------------
        */

        $buyer =
            $this->userModel->find(
                $buyerId
            );


        $buyerPhone =
            trim(
                (string)(
                    $buyer['phone']
                    ??
                    $buyer['phone_number']
                    ??
                    ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | Seller Phone
        |--------------------------------------------------------------------------
        */

        $seller =
            $this->userModel->find(
                $sellerId
            );


        $sellerPhone =
            trim(
                (string)(
                    $seller['phone']
                    ??
                    $seller['phone_number']
                    ??
                    ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | Generate Escrow Reference
        |--------------------------------------------------------------------------
        |
        | Do NOT use escrow_number.
        |
        | The existing Escrow model uses "reference".
        |
        */

        $reference =
            $this->generateReference();


        Logger::write(
            'escrow_service',
            [
                'step' => 'CREATE_REFERENCE_GENERATED',
                'reference' => $reference,
                'listing_id' => $listingId,
                'buyer_id' => $buyerId,
                'seller_id' => $sellerId
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Escrow Fee
        |--------------------------------------------------------------------------
        |
        | Keep the default fee at zero unless your existing service
        | already has a configured fee calculation method.
        |
        */

        $escrowFee = 0.0;


        /*
        |--------------------------------------------------------------------------
        | Seller Amount
        |--------------------------------------------------------------------------
        */

        $sellerAmount =
            max(
                0,
                $amount - $escrowFee
            );


        /*
        |--------------------------------------------------------------------------
        | Listing / Item Name
        |--------------------------------------------------------------------------
        */

        $item =
            $listing['title']
            ??
            $listing['item_name']
            ??
            $listing['item']
            ??
            $listing['description']
            ??
            'Escrow Transaction';


        $item =
            trim(
                (string)$item
            );


        if ($item === '') {

            $item = 'Escrow Transaction';
        }


        /*
        |--------------------------------------------------------------------------
        | Delivery Type
        |--------------------------------------------------------------------------
        */

        $deliveryType =
            trim(
                (string)(
                    $listing['delivery_type']
                    ??
                    'standard'
                )
            );


        if ($deliveryType === '') {

            $deliveryType = 'standard';
        }


        /*
        |--------------------------------------------------------------------------
        | Location
        |--------------------------------------------------------------------------
        |
        | Only included if your model/table supports it.
        | The current model may not require it.
        |
        */

        $location =
            trim(
                (string)(
                    $listing['location']
                    ??
                    ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | Expiration
        |--------------------------------------------------------------------------
        |
        | Seven days gives the buyer/seller enough time to complete
        | the transaction while preventing abandoned escrows from
        | remaining indefinitely.
        |
        */

        $expiresAt =
            date(
                'Y-m-d H:i:s',
                strtotime('+7 days')
            );


        /*
        |--------------------------------------------------------------------------
        | Build Escrow Data
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | This array matches the existing Models/Escrow::create()
        | structure.
        |
        */

        $data = [

            'reference' =>
                $reference,

            'listing_id' =>
                $listingId,

            'buyer_id' =>
                $buyerId,

            'seller_id' =>
                $sellerId,

            'buyer_phone' =>
                $buyerPhone,

            'seller_phone' =>
                $sellerPhone,

            'amount' =>
                $amount,

            'escrow_fee' =>
                $escrowFee,

            'seller_amount' =>
                $sellerAmount,

            'currency' =>
                $currency,

            'payment_method' =>
                'paystack',

            'delivery_type' =>
                $deliveryType,

            'payment_reference' =>
                null,

            'release_code' =>
                null,

            'status' =>
                'pending',

            'expires_at' =>
                $expiresAt
        ];


        Logger::write(
            'escrow_service',
            [
                'step' => 'CREATE_DATA_PREPARED',
                'reference' => $reference,
                'listing_id' => $listingId,
                'buyer_id' => $buyerId,
                'seller_id' => $sellerId,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending'
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Create Through Escrow Model
        |--------------------------------------------------------------------------
        |
        | This is the critical fix.
        |
        | DO NOT perform a raw INSERT here.
        |
        | The Escrow model owns the database structure.
        |
        */

        $created =
            $this->escrowModel->create(
                $data
            );


        Logger::write(
            'escrow_service',
            [
                'step' => 'CREATE_MODEL_RESULT',
                'reference' => $reference,
                'listing_id' => $listingId,
                'buyer_id' => $buyerId,
                'seller_id' => $sellerId,
                'result_type' => gettype($created),
                'result' => $created
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Normalize Model Result
        |--------------------------------------------------------------------------
        */

        if (
            $created === false
            ||
            $created === null
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' => 'CREATE_MODEL_FAILED',
                    'reference' => $reference,
                    'listing_id' => $listingId,
                    'buyer_id' => $buyerId,
                    'seller_id' => $sellerId
                ]
            );

            return null;
        }


        /*
        |--------------------------------------------------------------------------
        | If Model Returned An Array
        |--------------------------------------------------------------------------
        */

        if (
            is_array($created)
        ) {

            /*
             * Ensure the public reference is available.
             */
            if (
                empty($created['reference'])
            ) {

                $created['reference'] =
                    $reference;
            }


            if (
                !array_key_exists(
                    'success',
                    $created
                )
            ) {

                $created['success'] = true;
            }


            Logger::write(
                'escrow_service',
                [
                    'step' => 'CREATE_COMPLETE',
                    'reference' =>
                        $created['reference']
                        ??
                        $reference,
                    'listing_id' => $listingId,
                    'buyer_id' => $buyerId,
                    'seller_id' => $sellerId
                ]
            );


            return $created;
        }


        /*
        |--------------------------------------------------------------------------
        | Model Returned An ID
        |--------------------------------------------------------------------------
        */

        if (
            is_numeric($created)
        ) {

            $escrowId =
                (int)$created;


            Logger::write(
                'escrow_service',
                [
                    'step' => 'CREATE_MODEL_RETURNED_ID',
                    'escrow_id' => $escrowId,
                    'reference' => $reference
                ]
            );


            return [

                'success' =>
                    true,

                'id' =>
                    $escrowId,

                'reference' =>
                    $reference,

                'listing_id' =>
                    $listingId,

                'buyer_id' =>
                    $buyerId,

                'seller_id' =>
                    $sellerId,

                'amount' =>
                    $amount,

                'currency' =>
                    $currency,

                'status' =>
                    'pending'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Unknown Return Type
        |--------------------------------------------------------------------------
        */

        Logger::write(
            'escrow_service_error',
            [
                'step' =>
                    'CREATE_UNKNOWN_MODEL_RESULT',

                'reference' =>
                    $reference,

                'result_type' =>
                    gettype($created),

                'result' =>
                    $created
            ]
        );


        return null;

    }
    catch (Throwable $e) {

        Logger::write(
            'escrow_service_error',
            [
                'step' =>
                    'CREATE_EXCEPTION',

                'listing_id' =>
                    $listingId,

                'buyer_id' =>
                    $buyerId,

                'message' =>
                    $e->getMessage(),

                'file' =>
                    $e->getFile(),

                'line' =>
                    $e->getLine(),

                'trace' =>
                    $e->getTraceAsString()
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