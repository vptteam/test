<?php

declare(strict_types=1);

namespace Services\Escrow;

use Core\Database;
use Core\Logger;
use Models\User;
use Modules\Escrow\Models\Escrow;
use Throwable;

class EscrowApiService
{
    protected Escrow $escrowModel;

    protected User $userModel;

    protected EscrowConfirmationService $confirmationService;


    /**
     * ---------------------------------------------------------
     * Constructor
     * ---------------------------------------------------------
     */
    public function __construct()
    {
        $this->escrowModel =
            new Escrow();

        $this->userModel =
            new User();

        $this->confirmationService =
            new EscrowConfirmationService();

        Logger::write(
            'escrow_api_service',
            [
                'step' => 'CONSTRUCTOR',
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * GET ESCROW BY REFERENCE
     * ---------------------------------------------------------
     *
     * Internal API helper.
     *
     * This method is intentionally kept here so controllers
     * never need to instantiate the Escrow model directly.
     * ---------------------------------------------------------
     */
    public function getEscrowByReference(
        string $reference
    ): ?array {
        try {

            $reference =
                $this->normalizeReference(
                    $reference
                );

            if ($reference === '') {

                Logger::write(
                    'escrow_api_service_error',
                    [
                        'step' =>
                            'GET_ESCROW_REFERENCE_MISSING',
                    ]
                );

                return null;
            }


            $escrow =
                $this->escrowModel
                    ->findByReference(
                        $reference
                    );


            Logger::write(
                'escrow_api_service',
                [
                    'step' =>
                        'GET_ESCROW_BY_REFERENCE',

                    'reference' =>
                        $reference,

                    'found' =>
                        is_array($escrow)
                        && !empty($escrow),
                ]
            );


            if (
                !is_array($escrow)
                ||
                empty($escrow)
            ) {
                return null;
            }


            return $escrow;

        }
        catch (Throwable $e) {

            Logger::write(
                'escrow_api_service_error',
                [
                    'step' =>
                        'GET_ESCROW_BY_REFERENCE_EXCEPTION',

                    'reference' =>
                        $reference ?? '',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );

            return null;
        }
    }


    /**
     * ---------------------------------------------------------
     * VERIFY ESCROW
     * ---------------------------------------------------------
     *
     * Public API verification.
     *
     * Does not expose internal database IDs or private
     * payment/workflow information.
     * ---------------------------------------------------------
     */
    public function verify(
        string $reference
    ): array {
        try {

            $reference =
                $this->normalizeReference(
                    $reference
                );


            Logger::write(
                'escrow_api_service',
                [
                    'step' =>
                        'VERIFY_START',

                    'reference' =>
                        $reference,
                ]
            );


            if ($reference === '') {

                return [
                    'success' => false,

                    'message' =>
                        'Escrow reference is required.',
                ];
            }


            $escrow =
                $this->getEscrowByReference(
                    $reference
                );


            if (!$escrow) {

                Logger::write(
                    'escrow_api_service',
                    [
                        'step' =>
                            'VERIFY_ESCROW_NOT_FOUND',

                        'reference' =>
                            $reference,
                    ]
                );


                return [
                    'success' => false,

                    'message' =>
                        'Escrow transaction not found.',

                    'reference' =>
                        $reference,
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Seller
            |--------------------------------------------------------------------------
            */

            $seller = null;

            $sellerId =
                (int)(
                    $escrow['seller_id']
                    ?? 0
                );


            if ($sellerId > 0) {

                $seller =
                    $this->userModel
                        ->find(
                            $sellerId
                        );
            }


            /*
            |--------------------------------------------------------------------------
            | Public Response
            |--------------------------------------------------------------------------
            */

            $status =
                strtolower(
                    trim(
                        (string)(
                            $escrow['status']
                            ?? 'pending'
                        )
                    )
                );


            $currency =
                strtoupper(
                    trim(
                        (string)(
                            $escrow['currency']
                            ?? 'NGN'
                        )
                    )
                );


            $response = [

                'success' =>
                    true,

                'reference' =>
                    $reference,

                'status' =>
                    $status,

                'item' =>
                    $this->itemName(
                        $escrow
                    ),

                'amount' =>
                    $this->amount(
                        $escrow
                    ),

                'currency' =>
                    $currency,

                'location' =>
                    $this->location(
                        $escrow
                    ),

                'seller' =>
                    $this->sellerName(
                        $seller
                    ),

                'seller_verified' =>
                    $this->sellerVerified(
                        $seller
                    ),
            ];


            Logger::write(
                'escrow_api_service',
                [
                    'step' =>
                        'VERIFY_COMPLETE',

                    'reference' =>
                        $reference,

                    'status' =>
                        $status,
                ]
            );


            return $response;

        }
        catch (Throwable $e) {

            Logger::write(
                'escrow_api_service_error',
                [
                    'step' =>
                        'VERIFY_EXCEPTION',

                    'reference' =>
                        $reference ?? '',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),

                    'trace' =>
                        $e->getTraceAsString(),
                ]
            );


            return [
                'success' => false,

                'message' =>
                    'Unable to verify escrow.',
            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * CONFIRM RECEIPT
     * ---------------------------------------------------------
     *
     * API equivalent of:
     *
     * RECEIVED ESC-XXXXXX
     *
     * Authentication:
     *
     * escrow reference
     * +
     * buyer phone number
     *
     * The existing EscrowConfirmationService remains the
     * authoritative workflow for the actual confirmation.
     * ---------------------------------------------------------
     */
    public function confirmReceipt(
        string $reference,
        string $phone
    ): array {
        try {

            $reference =
                $this->normalizeReference(
                    $reference
                );


            $phone =
                $this->normalizePhone(
                    $phone
                );


            Logger::write(
                'escrow_api_service',
                [
                    'step' =>
                        'CONFIRM_RECEIPT_START',

                    'reference' =>
                        $reference,

                    'phone' =>
                        $phone,
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Validate Reference
            |--------------------------------------------------------------------------
            */

            if ($reference === '') {

                return [
                    'success' => false,

                    'message' =>
                        'Escrow reference is required.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Phone
            |--------------------------------------------------------------------------
            */

            if ($phone === '') {

                return [
                    'success' => false,

                    'message' =>
                        'Buyer phone number is required.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Load Escrow
            |--------------------------------------------------------------------------
            */

            $escrow =
                $this->getEscrowByReference(
                    $reference
                );


            if (!$escrow) {

                Logger::write(
                    'escrow_api_service',
                    [
                        'step' =>
                            'CONFIRM_ESCROW_NOT_FOUND',

                        'reference' =>
                            $reference,
                    ]
                );


                return [
                    'success' => false,

                    'message' =>
                        'Escrow transaction not found.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Already Completed
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
                $status === 'completed'
            ) {

                Logger::write(
                    'escrow_api_service',
                    [
                        'step' =>
                            'CONFIRM_ALREADY_COMPLETED',

                        'reference' =>
                            $reference,
                    ]
                );


                return [
                    'success' => false,

                    'message' =>
                        'This escrow has already been completed.',

                    'reference' =>
                        $reference,

                    'status' =>
                        $status,
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Find Buyer
            |--------------------------------------------------------------------------
            */

            $buyer =
                $this->findUserByPhone(
                    $phone
                );


            if (!$buyer) {

                Logger::write(
                    'escrow_api_service_error',
                    [
                        'step' =>
                            'BUYER_PHONE_NOT_REGISTERED',

                        'reference' =>
                            $reference,
                    ]
                );


                return [
                    'success' => false,

                    'message' =>
                        'Buyer phone number is not registered.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Authorize Buyer
            |--------------------------------------------------------------------------
            */

            $expectedBuyerId =
                (int)(
                    $escrow['buyer_id']
                    ?? 0
                );


            $actualBuyerId =
                (int)(
                    $buyer['id']
                    ?? 0
                );


            Logger::write(
                'escrow_api_service',
                [
                    'step' =>
                        'BUYER_AUTHORIZATION',

                    'reference' =>
                        $reference,

                    'expected_buyer_id' =>
                        $expectedBuyerId,

                    'actual_buyer_id' =>
                        $actualBuyerId,
                ]
            );


            if (
                $expectedBuyerId <= 0
                ||
                $actualBuyerId <= 0
                ||
                $expectedBuyerId !== $actualBuyerId
            ) {

                Logger::write(
                    'escrow_api_service_error',
                    [
                        'step' =>
                            'BUYER_AUTHORIZATION_FAILED',

                        'reference' =>
                            $reference,
                    ]
                );


                return [
                    'success' => false,

                    'message' =>
                        'This phone number is not authorized to confirm this escrow.',
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Existing Confirmation Workflow
            |--------------------------------------------------------------------------
            |
            | DO NOT duplicate:
            |
            | - escrow state transition
            | - seller notification
            | - admin notification
            | - payout-bank handling
            | - wallet handling
            | - transaction creation
            |
            | EscrowConfirmationService owns those operations.
            |--------------------------------------------------------------------------
            */

            $result =
                $this->confirmationService
                    ->confirm(
                        $reference,
                        $actualBuyerId
                    );


            Logger::write(
                'escrow_api_service',
                [
                    'step' =>
                        'CONFIRMATION_SERVICE_RESULT',

                    'reference' =>
                        $reference,

                    'buyer_id' =>
                        $actualBuyerId,

                    'result' =>
                        $result,
                ]
            );


            if (!is_array($result)) {

                Logger::write(
                    'escrow_api_service_error',
                    [
                        'step' =>
                            'CONFIRMATION_INVALID_RESULT',

                        'reference' =>
                            $reference,
                    ]
                );


                return [
                    'success' => false,

                    'message' =>
                        'Unable to confirm receipt.',
                ];
            }


            return $result;

        }
        catch (Throwable $e) {

            Logger::write(
                'escrow_api_service_error',
                [
                    'step' =>
                        'CONFIRM_RECEIPT_EXCEPTION',

                    'reference' =>
                        $reference ?? '',

                    'phone' =>
                        $phone ?? '',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),

                    'trace' =>
                        $e->getTraceAsString(),
                ]
            );


            return [
                'success' => false,

                'message' =>
                    'Unable to confirm receipt.',
            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * FIND USER BY PHONE
     * ---------------------------------------------------------
     *
     * Supports:
     *
     * 08012345678
     * 2348012345678
     * +2348012345678
     *
     * ---------------------------------------------------------
     */
    protected function findUserByPhone(
        string $phone
    ): ?array {
        try {

            $phone =
                $this->normalizePhone(
                    $phone
                );


            if ($phone === '') {
                return null;
            }


            $db =
                Database::getInstance()
                    ->connection();


            /*
            |--------------------------------------------------------------------------
            | Build Possible Formats
            |--------------------------------------------------------------------------
            */

            $variants = [
                $phone,
            ];


            if (
                str_starts_with(
                    $phone,
                    '234'
                )
                &&
                strlen($phone) > 3
            ) {

                $local =
                    '0'
                    .
                    substr(
                        $phone,
                        3
                    );


                $variants[] =
                    $local;
            }


            if (
                str_starts_with(
                    $phone,
                    '0'
                )
                &&
                strlen($phone) === 11
            ) {

                $international =
                    '234'
                    .
                    substr(
                        $phone,
                        1
                    );


                $variants[] =
                    $international;
            }


            /*
            |--------------------------------------------------------------------------
            | Remove Duplicate Variants
            |--------------------------------------------------------------------------
            */

            $variants =
                array_values(
                    array_unique(
                        array_filter(
                            $variants
                        )
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | Query
            |--------------------------------------------------------------------------
            */

            foreach ($variants as $variant) {

                $stmt =
                    $db->prepare(
                        "
                        SELECT *
                        FROM users
                        WHERE phone = ?
                        LIMIT 1
                        "
                    );


                $stmt->execute(
                    [
                        $variant,
                    ]
                );


                $user =
                    $stmt->fetch();


                if (
                    is_array($user)
                    &&
                    !empty($user)
                ) {

                    Logger::write(
                        'escrow_api_service',
                        [
                            'step' =>
                                'BUYER_FOUND_BY_PHONE',

                            'matched_format' =>
                                $variant,

                            'user_id' =>
                                $user['id']
                                ?? null,
                        ]
                    );


                    return $user;
                }
            }


            return null;

        }
        catch (Throwable $e) {

            Logger::write(
                'escrow_api_service_error',
                [
                    'step' =>
                        'FIND_USER_BY_PHONE_EXCEPTION',

                    'phone' =>
                        $phone ?? '',

                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );


            return null;
        }
    }


    /**
     * ---------------------------------------------------------
     * NORMALIZE REFERENCE
     * ---------------------------------------------------------
     */
    protected function normalizeReference(
        string $reference
    ): string {

        return strtoupper(
            trim(
                $reference
            )
        );
    }


    /**
     * ---------------------------------------------------------
     * NORMALIZE PHONE
     * ---------------------------------------------------------
     */
    protected function normalizePhone(
        string $phone
    ): string {

        $phone =
            trim(
                $phone
            );


        /*
        |--------------------------------------------------------------------------
        | Remove WhatsApp Prefix
        |--------------------------------------------------------------------------
        */

        $phone =
            preg_replace(
                '/^whatsapp:/i',
                '',
                $phone
            )
            ?? '';


        /*
        |--------------------------------------------------------------------------
        | Keep Digits Only
        |--------------------------------------------------------------------------
        */

        $phone =
            preg_replace(
                '/[^0-9]/',
                '',
                $phone
            )
            ?? '';


        /*
        |--------------------------------------------------------------------------
        | Nigerian Local -> International
        |--------------------------------------------------------------------------
        */

        if (
            str_starts_with(
                $phone,
                '0'
            )
            &&
            strlen($phone) === 11
        ) {

            $phone =
                '234'
                .
                substr(
                    $phone,
                    1
                );
        }


        return $phone;
    }


    /**
     * ---------------------------------------------------------
     * AMOUNT
     * ---------------------------------------------------------
     */
    protected function amount(
        array $escrow
    ): ?float {

        $value =
            $escrow['amount']
            ??
            $escrow['total_amount']
            ??
            null;


        if (
            $value === null
            ||
            !is_numeric($value)
        ) {

            return null;
        }


        return (float)$value;
    }


    /**
     * ---------------------------------------------------------
     * ITEM NAME
     * ---------------------------------------------------------
     */
    protected function itemName(
        array $escrow
    ): string {

        $item =
            $escrow['item']
            ??
            $escrow['item_name']
            ??
            $escrow['title']
            ??
            $escrow['description']
            ??
            'Escrow Transaction';


        return trim(
            (string)$item
        );
    }


    /**
     * ---------------------------------------------------------
     * LOCATION
     * ---------------------------------------------------------
     */
    protected function location(
        array $escrow
    ): ?string {

        $location =
            $escrow['location']
            ??
            null;


        if (
            $location === null
            ||
            trim((string)$location) === ''
        ) {

            return null;
        }


        return trim(
            (string)$location
        );
    }


    /**
     * ---------------------------------------------------------
     * SELLER NAME
     * ---------------------------------------------------------
     */
    protected function sellerName(
        ?array $seller
    ): ?string {

        if (!$seller) {
            return null;
        }


        $name =
            $seller['name']
            ??
            $seller['full_name']
            ??
            null;


        if (
            $name === null
            ||
            trim((string)$name) === ''
        ) {

            return null;
        }


        return trim(
            (string)$name
        );
    }


    /**
     * ---------------------------------------------------------
     * SELLER VERIFIED
     * ---------------------------------------------------------
     */
    protected function sellerVerified(
        ?array $seller
    ): bool {

        if (!$seller) {
            return false;
        }


        if (
            array_key_exists(
                'verified',
                $seller
            )
        ) {

            return (bool)$seller['verified'];
        }


        if (
            array_key_exists(
                'is_verified',
                $seller
            )
        ) {

            return (bool)$seller['is_verified'];
        }


        return false;
    }
}