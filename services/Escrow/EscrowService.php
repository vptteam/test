<?php

declare(strict_types=1);

namespace Services\Escrow;

use Core\Database;
use Core\Logger;
use Models\Listing;
use Models\User;
use Models\Escrow;
use Services\Payments\PaystackGateway;
use Throwable;

class EscrowService
{
protected Database $db;

protected Escrow $escrowModel;

protected Listing $listingModel;

protected User $userModel;

protected PaystackGateway $paystack;


public function __construct()
{
    $this->db = Database::getInstance();

    $this->escrowModel = new Escrow();

    $this->listingModel = new Listing();

    $this->userModel = new User();

    $this->paystack = new PaystackGateway();

    Logger::write(
        'escrow_service',
        [
            'step' => 'CONSTRUCTOR'
        ]
    );
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
|
| Initializes a Paystack payment for an existing escrow.
|
| IMPORTANT:
|
| - $reference is the public escrow reference.
| - Escrow model uses "reference", NOT escrow_number.
| - Paystack reference is separate from escrow reference.
| - The amount sent to Paystack is the buyer's final total.
|
*/


public function initializePayment(
    string $reference,
    string $email,
    string $callback
): array {

    $reference = strtoupper(
        trim($reference)
    );

    $email = trim($email);
    $callback = trim($callback);

    try {

        Logger::write(
            'escrow_service',
            [
                'step' => 'PAYMENT_INITIALIZE_START',
                'escrow_reference' => $reference,
                'email' => $email,
                'callback' => $callback
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | VALIDATE INPUT
        |--------------------------------------------------------------------------
        */

        if ($reference === '') {

            return [
                'success' => false,
                'message' => 'Escrow reference is required.'
            ];
        }


        if (
            $email === ''
            ||
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' => 'PAYMENT_EMAIL_INVALID',
                    'escrow_reference' => $reference,
                    'email' => $email
                ]
            );

            return [
                'success' => false,
                'message' => 'A valid email address is required.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | FIND ESCROW
        |--------------------------------------------------------------------------
        */

        $escrow =
            $this->escrowModel->findByReference(
                $reference
            );


        if (
            !is_array($escrow)
            ||
            empty($escrow['id'])
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' => 'PAYMENT_ESCROW_NOT_FOUND',
                    'escrow_reference' => $reference
                ]
            );

            return [
                'success' => false,
                'message' => 'Escrow transaction not found.'
            ];
        }


        $escrowId =
            (int)$escrow['id'];


        /*
        |--------------------------------------------------------------------------
        | CONFIRM DATABASE REFERENCE
        |--------------------------------------------------------------------------
        */

        $databaseReference =
            strtoupper(
                trim(
                    (string)(
                        $escrow['reference']
                        ?? ''
                    )
                )
            );


        if (
            $databaseReference === ''
            ||
            $databaseReference !== $reference
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' => 'PAYMENT_ESCROW_REFERENCE_MISMATCH',
                    'requested_reference' => $reference,
                    'database_reference' =>
                        $databaseReference,
                    'escrow_id' => $escrowId
                ]
            );

            return [
                'success' => false,
                'message' =>
                    'Escrow reference could not be verified.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | STATUS
        |--------------------------------------------------------------------------
        */

        $status =
            strtolower(
                trim(
                    (string)(
                        $escrow['status']
                        ?? ''
                    )
                )
            );


        if (
            in_array(
                $status,
                [
                    'paid',
                    'item_sent',
                    'awaiting_payout',
                    'buyer_confirmed',
                    'completed'
                ],
                true
            )
        ) {

            Logger::write(
                'escrow_service',
                [
                    'step' => 'PAYMENT_ALREADY_PROCESSED',
                    'escrow_id' => $escrowId,
                    'escrow_reference' => $reference,
                    'status' => $status
                ]
            );

            return [
                'success' => false,
                'message' =>
                    'This escrow has already been paid or completed.',
                'reference' => $reference,
                'escrow_id' => $escrowId,
                'status' => $status
            ];
        }


        if ($status !== 'pending') {

            Logger::write(
                'escrow_service_error',
                [
                    'step' => 'PAYMENT_INVALID_ESCROW_STATUS',
                    'escrow_id' => $escrowId,
                    'escrow_reference' => $reference,
                    'status' => $status
                ]
            );

            return [
                'success' => false,
                'message' =>
                    'This escrow cannot currently accept payment.',
                'reference' => $reference,
                'escrow_id' => $escrowId,
                'status' => $status
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | ESCROW AMOUNT
        |--------------------------------------------------------------------------
        */

        $amount =
            (float)(
                $escrow['amount']
                ?? 0
            );


        if ($amount <= 0) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' => 'PAYMENT_INVALID_AMOUNT',
                    'escrow_id' => $escrowId,
                    'escrow_reference' => $reference,
                    'amount' => $amount
                ]
            );

            return [
                'success' => false,
                'message' => 'Invalid escrow amount.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | CALCULATE BUYER TOTAL
        |--------------------------------------------------------------------------
        */

        $fees =
            $this->calculateBuyerTotal(
                $amount
            );


        $buyerTotal =
            (float)(
                $fees['buyer_total']
                ?? 0
            );


        if ($buyerTotal <= 0) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' => 'PAYMENT_INVALID_BUYER_TOTAL',
                    'escrow_id' => $escrowId,
                    'escrow_reference' => $reference,
                    'fees' => $fees
                ]
            );

            return [
                'success' => false,
                'message' =>
                    'Unable to calculate the payment amount.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | PAYMENT REFERENCE
        |--------------------------------------------------------------------------
        |
        | Escrow reference:
        |
        |     SDM-000037
        |
        | Paystack reference:
        |
        |     ESC-SDM-000037-XXXXXXXX
        |
        */

        $existingPaymentReference =
            strtoupper(
                trim(
                    (string)(
                        $escrow['payment_reference']
                        ?? ''
                    )
                )
            );


        if (
            $existingPaymentReference !== ''
        ) {

            $paymentReference =
                $existingPaymentReference;

        } else {

            $paymentReference =
                'ESC-'
                .
                $reference
                .
                '-'
                .
                strtoupper(
                    bin2hex(
                        random_bytes(6)
                    )
                );
        }


        Logger::write(
            'escrow_service',
            [
                'step' => 'PAYMENT_REFERENCE_READY',
                'escrow_id' => $escrowId,
                'escrow_reference' => $reference,
                'payment_reference' =>
                    $paymentReference
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | METADATA
        |--------------------------------------------------------------------------
        */

        $metadata = [

            'type' =>
                'escrow_payment',

            'escrow_id' =>
                $escrowId,

            'escrow_reference' =>
                $reference,

            'payment_reference' =>
                $paymentReference,

            'listing_id' =>
                (int)(
                    $escrow['listing_id']
                    ?? 0
                ),

            'buyer_id' =>
                (int)(
                    $escrow['buyer_id']
                    ?? 0
                ),

            'seller_id' =>
                (int)(
                    $escrow['seller_id']
                    ?? 0
                ),

            'escrow_amount' =>
                round(
                    (float)(
                        $fees['amount']
                        ?? $amount
                    ),
                    2
                ),

            'escrow_fee' =>
                round(
                    (float)(
                        $fees['escrow_fee']
                        ?? 0
                    ),
                    2
                ),

            'paystack_fee' =>
                round(
                    (float)(
                        $fees['paystack_fee']
                        ?? 0
                    ),
                    2
                ),

            'buyer_total' =>
                round(
                    $buyerTotal,
                    2
                )
        ];


        /*
        |--------------------------------------------------------------------------
        | INITIALIZE PAYSTACK
        |--------------------------------------------------------------------------
        */

        Logger::write(
            'escrow_service',
            [
                'step' => 'PAYMENT_CALLING_PAYSTACK',
                'escrow_id' => $escrowId,
                'escrow_reference' => $reference,
                'payment_reference' =>
                    $paymentReference,
                'amount' => $buyerTotal,
                'email' => $email
            ]
        );


        $payment =
            $this->paystack->initialize(
                (int)round(
                    $buyerTotal
                ),
                $email,
                $paymentReference,
                $callback,
                $metadata
            );


        if (
            !is_array($payment)
            ||
            !($payment['success'] ?? false)
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' =>
                        'PAYMENT_PAYSTACK_INITIALIZATION_FAILED',

                    'escrow_id' =>
                        $escrowId,

                    'escrow_reference' =>
                        $reference,

                    'payment_reference' =>
                        $paymentReference,

                    'payment' =>
                        $payment
                ]
            );

            return [
                'success' => false,

                'message' =>
                    $payment['message']
                    ??
                    'Unable to initialize escrow payment.',

                'reference' =>
                    $reference,

                'payment_reference' =>
                    $paymentReference,

                'escrow_id' =>
                    $escrowId,

                'raw' =>
                    $payment['raw']
                    ?? null
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | RESOLVE PAYSTACK REFERENCE
        |--------------------------------------------------------------------------
        */

        $returnedPaymentReference =
            strtoupper(
                trim(
                    (string)(
                        $payment['reference']
                        ?? $paymentReference
                    )
                )
            );


        if (
            $returnedPaymentReference === ''
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' =>
                        'PAYMENT_PAYSTACK_REFERENCE_MISSING',

                    'escrow_id' =>
                        $escrowId,

                    'escrow_reference' =>
                        $reference
                ]
            );

            return [
                'success' => false,
                'message' =>
                    'Paystack did not return a payment reference.',
                'reference' =>
                    $reference,
                'escrow_id' =>
                    $escrowId
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | AUTHORIZATION URL
        |--------------------------------------------------------------------------
        */

        $authorizationUrl =
            trim(
                (string)(
                    $payment['authorization_url']
                    ?? ''
                )
            );


        if (
            $authorizationUrl === ''
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' =>
                        'PAYMENT_AUTHORIZATION_URL_MISSING',

                    'escrow_id' =>
                        $escrowId,

                    'escrow_reference' =>
                        $reference,

                    'payment_reference' =>
                        $returnedPaymentReference
                ]
            );

            return [
                'success' => false,
                'message' =>
                    'Paystack did not return a payment link.',
                'reference' =>
                    $reference,
                'payment_reference' =>
                    $returnedPaymentReference,
                'escrow_id' =>
                    $escrowId
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | Persist Paystack reference
        |--------------------------------------------------------------------------
        |
        | Initialization does not mark the escrow paid, but the exact Paystack
        | reference must be stored so payment-status, duplicate protection and
        | webhook reconciliation all use the same identifier.
        |--------------------------------------------------------------------------
        */

        if ($returnedPaymentReference !== '') {
            $stored = $this->escrowModel->update(
                $escrowId,
                [
                    'payment_reference' => $returnedPaymentReference,
                    'payment_method' => 'paystack',
                ]
            );

            Logger::write('escrow_service', [
                'step' => 'PAYMENT_REFERENCE_PERSISTED',
                'escrow_id' => $escrowId,
                'escrow_reference' => $reference,
                'payment_reference' => $returnedPaymentReference,
                'result' => $stored,
            ]);

            if (!$stored) {
                return [
                    'success' => false,
                    'message' => 'Unable to save the Paystack payment reference.',
                    'reference' => $reference,
                    'payment_reference' => $returnedPaymentReference,
                    'escrow_id' => $escrowId,
                ];
            }

            $escrow['payment_reference'] = $returnedPaymentReference;
            $escrow['payment_method'] = 'paystack';
        }


        /*
        |--------------------------------------------------------------------------
        | IMPORTANT
        |--------------------------------------------------------------------------
        |
        | Do NOT mark escrow as PAID here.
        |
        | Payment is only considered paid after:
        |
        | Paystack webhook
        |        ↓
        | transaction verified
        |        ↓
        | PaystackEscrowPaymentService::process()
        |        ↓
        | Escrow::markPaid()
        |
        |--------------------------------------------------------------------------
        */


        Logger::write(
            'escrow_service',
            [
                'step' =>
                    'PAYMENT_INITIALIZE_COMPLETE',

                'escrow_id' =>
                    $escrowId,

                'escrow_reference' =>
                    $reference,

                'payment_reference' =>
                    $returnedPaymentReference,

                'amount' =>
                    $buyerTotal
            ]
        );


        return [

            'success' =>
                true,

            'reference' =>
                $reference,

            'payment_reference' =>
                $returnedPaymentReference,

            'escrow_id' =>
                $escrowId,

            'escrow' =>
                $escrow,

            'amount' =>
                $fees['amount']
                ?? $amount,

            'escrow_fee' =>
                $fees['escrow_fee']
                ?? 0,

            'paystack_fee' =>
                $fees['paystack_fee']
                ?? 0,

            'total' =>
                $buyerTotal,

            'authorization_url' =>
                $authorizationUrl,

            'access_code' =>
                $payment['access_code']
                ?? null,

            'raw' =>
                $payment['raw']
                ?? null
        ];


    } catch (Throwable $e) {

        Logger::write(
            'escrow_payment_error',
            [
                'step' =>
                    'INITIALIZE_EXCEPTION',

                'reference' =>
                    $reference,

                'email' =>
                    $email,

                'callback' =>
                    $callback,

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

        return [
            'success' => false,
            'message' =>
                'Unable to initialize escrow payment.'
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
    string $reference,
    string $paymentReference
): bool {

    try {

        $reference =
            strtoupper(
                trim($reference)
            );

        $paymentReference =
            trim($paymentReference);


        Logger::write(
            'escrow_service',
            [
                'step' =>
                    'BUYER_PAID_START',

                'reference' =>
                    $reference,

                'payment_reference' =>
                    $paymentReference
            ]
        );


        if ($reference === '') {

            return false;
        }


        if ($paymentReference === '') {

            return false;
        }


        /*
        |--------------------------------------------------------------------------
        | Find Escrow
        |--------------------------------------------------------------------------
        */

        $escrow =
            $this->escrowModel->findByReference(
                $reference
            );


        if (
            !is_array($escrow)
            ||
            empty($escrow['id'])
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' =>
                        'BUYER_PAID_ESCROW_NOT_FOUND',

                    'reference' =>
                        $reference
                ]
            );

            return false;
        }


        $escrowId =
            (int)$escrow['id'];


        /*
        |--------------------------------------------------------------------------
        | Mark Payment Received
        |--------------------------------------------------------------------------
        */

        if (
            !method_exists(
                $this->escrowModel,
                'markPaid'
            )
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' =>
                        'MARK_PAID_METHOD_MISSING',

                    'reference' =>
                        $reference,

                    'escrow_id' =>
                        $escrowId
                ]
            );

            return false;
        }


        $result =
            $this->escrowModel->markPaid(
                $escrowId,
                $paymentReference
            );


        Logger::write(
            'escrow_service',
            [
                'step' =>
                    'BUYER_PAID_RESULT',

                'reference' =>
                    $reference,

                'escrow_id' =>
                    $escrowId,

                'payment_reference' =>
                    $paymentReference,

                'result' =>
                    $result
            ]
        );


        return (bool)$result;


    } catch (Throwable $e) {

        Logger::write(
            'escrow_service_error',
            [
                'step' =>
                    'BUYER_PAID_EXCEPTION',

                'reference' =>
                    $reference,

                'payment_reference' =>
                    $paymentReference,

                'message' =>
                    $e->getMessage(),

                'file' =>
                    $e->getFile(),

                'line' =>
                    $e->getLine()
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
    string $reference
): bool {

    try {

        $reference = strtoupper(
            trim($reference)
        );

        Logger::write(
            'escrow_service',
            [
                'step' => 'BUYER_CONFIRMED_START',
                'reference' => $reference
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Validate Reference
        |--------------------------------------------------------------------------
        */

        if ($reference === '') {

            Logger::write(
                'escrow_service_error',
                [
                    'step' =>
                        'BUYER_CONFIRM_REFERENCE_MISSING'
                ]
            );

            return false;
        }


        /*
        |--------------------------------------------------------------------------
        | Find Escrow By Public Reference
        |--------------------------------------------------------------------------
        */

        $escrow =
            $this->escrowModel->findByReference(
                $reference
            );


        Logger::write(
            'escrow_service',
            [
                'step' =>
                    'BUYER_CONFIRM_ESCROW_LOOKUP',

                'reference' =>
                    $reference,

                'found' =>
                    is_array($escrow)
            ]
        );


        if (
            !is_array($escrow)
            ||
            empty($escrow['id'])
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' =>
                        'BUYER_CONFIRM_ESCROW_NOT_FOUND',

                    'reference' =>
                        $reference
                ]
            );

            return false;
        }


        /*
        |--------------------------------------------------------------------------
        | Resolve Internal Escrow ID
        |--------------------------------------------------------------------------
        */

        $escrowId =
            (int)$escrow['id'];


        Logger::write(
            'escrow_service',
            [
                'step' =>
                    'BUYER_CONFIRM_ESCROW_ID_RESOLVED',

                'reference' =>
                    $reference,

                'escrow_id' =>
                    $escrowId,

                'status' =>
                    $escrow['status']
                    ?? null
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Confirm Buyer Receipt
        |--------------------------------------------------------------------------
        |
        | The model expects the INTERNAL escrow ID.
        |
        */

        if (
            !method_exists(
                $this->escrowModel,
                'buyerConfirm'
            )
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step' =>
                        'BUYER_CONFIRM_METHOD_MISSING',

                    'reference' =>
                        $reference,

                    'escrow_id' =>
                        $escrowId
                ]
            );

            return false;
        }


        $result =
            $this->escrowModel->buyerConfirm(
                $escrowId
            );


        Logger::write(
            'escrow_service',
            [
                'step' =>
                    'BUYER_CONFIRM_RESULT',

                'reference' =>
                    $reference,

                'escrow_id' =>
                    $escrowId,

                'result' =>
                    $result
            ]
        );


        return (bool)$result;


    }
    catch (Throwable $e) {

        Logger::write(
            'escrow_service_error',
            [
                'step' =>
                    'BUYER_CONFIRM_EXCEPTION',

                'reference' =>
                    $reference,

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

        return false;
    }
}

 
/*
|--------------------------------------------------------------------------
| SELLER CONFIRMATION
|--------------------------------------------------------------------------
*/

public function sellerConfirmed(
    string $reference
): bool {

    try {

        $reference = strtoupper(
            trim($reference)
        );

        if ($reference === '') {

            return false;
        }

        Logger::write(
            'escrow_service',
            [
                'step'      => 'SELLER_CONFIRMED_START',
                'reference' => $reference
            ]
        );

        $escrow =
            $this->escrowModel->findByReference(
                $reference
            );

        if (
            !is_array($escrow)
            ||
            empty($escrow['id'])
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step'      => 'SELLER_CONFIRM_ESCROW_NOT_FOUND',
                    'reference' => $reference
                ]
            );

            return false;
        }

        $escrowId =
            (int)$escrow['id'];

        /*
        |--------------------------------------------------------------------------
        | Prefer sellerConfirm()
        |--------------------------------------------------------------------------
        */

        if (
            method_exists(
                $this->escrowModel,
                'sellerConfirm'
            )
        ) {

            return (bool)$this->escrowModel->sellerConfirm(
                $escrowId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Legacy sellerConfirmed()
        |--------------------------------------------------------------------------
        */

        if (
            method_exists(
                $this->escrowModel,
                'sellerConfirmed'
            )
        ) {

            return (bool)$this->escrowModel->sellerConfirmed(
                $escrowId
            );
        }

        Logger::write(
            'escrow_service_error',
            [
                'step'      => 'SELLER_CONFIRM_METHOD_MISSING',
                'reference' => $reference,
                'escrow_id' => $escrowId
            ]
        );

        return false;

    } catch (Throwable $e) {

        Logger::write(
            'escrow_service_error',
            [
                'step'      => 'SELLER_CONFIRM_EXCEPTION',
                'reference' => $reference,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine()
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
    string $reference
): bool {

    try {

        $reference = strtoupper(
            trim($reference)
        );

        if ($reference === '') {
            return false;
        }

        $escrow =
            $this->escrowModel->findByReference(
                $reference
            );

        if (
            !is_array($escrow)
            ||
            empty($escrow['id'])
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step'      => 'RELEASE_ESCROW_NOT_FOUND',
                    'reference' => $reference
                ]
            );

            return false;
        }

        $escrowId =
            (int)$escrow['id'];

        if (
            !method_exists(
                $this->escrowModel,
                'release'
            )
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step'      => 'RELEASE_METHOD_MISSING',
                    'reference' => $reference,
                    'escrow_id' => $escrowId
                ]
            );

            return false;
        }

        return (bool)$this->escrowModel->release(
            $escrowId
        );

    } catch (Throwable $e) {

        Logger::write(
            'escrow_service_error',
            [
                'step'      => 'RELEASE_EXCEPTION',
                'reference' => $reference,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine()
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
    string $reference
): bool {

    try {

        $reference = strtoupper(
            trim($reference)
        );

        if ($reference === '') {
            return false;
        }

        $escrow =
            $this->escrowModel->findByReference(
                $reference
            );

        if (
            !is_array($escrow)
            ||
            empty($escrow['id'])
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step'      => 'CANCEL_ESCROW_NOT_FOUND',
                    'reference' => $reference
                ]
            );

            return false;
        }

        $escrowId =
            (int)$escrow['id'];

        if (
            !method_exists(
                $this->escrowModel,
                'cancel'
            )
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step'      => 'CANCEL_METHOD_MISSING',
                    'reference' => $reference,
                    'escrow_id' => $escrowId
                ]
            );

            return false;
        }

        return (bool)$this->escrowModel->cancel(
            $escrowId
        );

    } catch (Throwable $e) {

        Logger::write(
            'escrow_service_error',
            [
                'step'      => 'CANCEL_EXCEPTION',
                'reference' => $reference,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine()
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
    string $reference
): bool {

    try {

        $reference = strtoupper(
            trim($reference)
        );

        if ($reference === '') {
            return false;
        }

        $escrow =
            $this->escrowModel->findByReference(
                $reference
            );

        if (
            !is_array($escrow)
            ||
            empty($escrow['id'])
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step'      => 'REFUND_ESCROW_NOT_FOUND',
                    'reference' => $reference
                ]
            );

            return false;
        }

        $escrowId =
            (int)$escrow['id'];

        if (
            !method_exists(
                $this->escrowModel,
                'refund'
            )
        ) {

            Logger::write(
                'escrow_service_error',
                [
                    'step'      => 'REFUND_METHOD_MISSING',
                    'reference' => $reference,
                    'escrow_id' => $escrowId
                ]
            );

            return false;
        }

        return (bool)$this->escrowModel->refund(
            $escrowId
        );

    } catch (Throwable $e) {

        Logger::write(
            'escrow_service_error',
            [
                'step'      => 'REFUND_EXCEPTION',
                'reference' => $reference,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine()
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
    string $reference
): ?array {

    try {

        $reference = strtoupper(
            trim($reference)
        );

        if ($reference === '') {
            return null;
        }

        return $this->escrowModel->findByReference(
            $reference
        );

    } catch (Throwable $e) {

        Logger::write(
            'escrow_service_error',
            [
                'step'      => 'FIND_EXCEPTION',
                'reference' => $reference,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine()
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