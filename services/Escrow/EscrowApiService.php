<?php

declare(strict_types=1);

namespace Services\Escrow;

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
                'step' => 'CONSTRUCTOR'
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * Verify Escrow / Listing
     * ---------------------------------------------------------
     *
     * Used by:
     *
     * Website
     * Mobile App
     * SMS
     * USSD
     *
     * Example:
     *
     * VERIFY SDM-000033
     *
     * ---------------------------------------------------------
     */
    public function verify(
        string $reference
    ): array {

        try {

            $reference =
                strtoupper(
                    trim($reference)
                );

            Logger::write(
                'escrow_api_service',
                [
                    'step' => 'VERIFY_START',
                    'reference' => $reference
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
                    'message' => 'Escrow reference is required.'
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Load Escrow
            |--------------------------------------------------------------------------
            */

            $escrow =
                $this->escrowModel
                    ->findByReference(
                        $reference
                    );


            Logger::write(
                'escrow_api_service',
                [
                    'step' => 'ESCROW_LOOKUP',
                    'reference' => $reference,
                    'found' => $escrow !== null
                ]
            );


            if (!$escrow) {

                return [
                    'success' => false,
                    'message' => 'Escrow transaction not found.',
                    'reference' => $reference
                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Determine Seller
            |--------------------------------------------------------------------------
            */

            $seller = null;

            if (
                !empty($escrow['seller_id'])
            ) {

                $seller =
                    $this->userModel->find(
                        (int)$escrow['seller_id']
                    );
            }


            /*
            |--------------------------------------------------------------------------
            | Build Safe Public Response
            |--------------------------------------------------------------------------
            |
            | DO NOT expose:
            |
            | - seller ID
            | - buyer ID
            | - internal database IDs
            | - recipient codes
            | - private account information
            | - internal workflow data
            |
            */

            $amount =
                $this->amount(
                    $escrow
                );


            $response = [

                'success' =>
                    true,

                'reference' =>
                    $reference,

                'status' =>
                    $escrow['status']
                    ?? null,

                'item' =>
                    $this->itemName(
                        $escrow
                    ),

                'amount' =>
                    $amount,

                'currency' =>
                    $escrow['currency']
                    ?? 'NGN',

                'location' =>
                    $escrow['location']
                    ?? null,

                'seller' =>
                    $this->sellerName(
                        $seller
                    ),

                'seller_verified' =>
                    $this->sellerVerified(
                        $seller
                    )

            ];


            Logger::write(
                'escrow_api_service',
                [
                    'step' => 'VERIFY_COMPLETE',
                    'reference' => $reference,
                    'status' =>
                        $escrow['status']
                        ?? null
                ]
            );


            return $response;

        }
        catch (Throwable $e) {

            Logger::write(
                'escrow_api_service_error',
                [
                    'step' => 'VERIFY_EXCEPTION',
                    'reference' => $reference,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            );


            return [

                'success' => false,

                'message' =>
                    'Unable to verify escrow.'

            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * Confirm Receipt From API
     * ---------------------------------------------------------
     *
     * This is the API equivalent of:
     *
     * RECEIVED ESCXXXXXXXX
     *
     * Security:
     *
     * reference + buyer phone
     *
     * are both required.
     *
     * The phone must resolve to the same internal
     * user ID stored as escrow.buyer_id.
     *
     * ---------------------------------------------------------
     */
    public function confirmReceipt(
        string $reference,
        string $phone
    ): array {

        try {

            $reference =
                strtoupper(
                    trim($reference)
                );

            $phone =
                $this->normalizePhone(
                    $phone
                );


            Logger::write(
                'escrow_api_service',
                [
                    'step' => 'CONFIRM_RECEIPT_START',
                    'reference' => $reference,
                    'phone' => $phone
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Validate Input
            |--------------------------------------------------------------------------
            */

            if ($reference === '') {

                return [

                    'success' => false,

                    'message' =>
                        'Escrow reference is required.'

                ];
            }


            if ($phone === '') {

                return [

                    'success' => false,

                    'message' =>
                        'Buyer phone number is required.'

                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Load Escrow
            |--------------------------------------------------------------------------
            */

            $escrow =
                $this->escrowModel
                    ->findByReference(
                        $reference
                    );


            if (!$escrow) {

                Logger::write(
                    'escrow_api_service',
                    [
                        'step' => 'CONFIRM_ESCROW_NOT_FOUND',
                        'reference' => $reference
                    ]
                );


                return [

                    'success' => false,

                    'message' =>
                        'Escrow transaction not found.'

                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Already Completed
            |--------------------------------------------------------------------------
            */

            if (
                ($escrow['status'] ?? '')
                ===
                'completed'
            ) {

                return [

                    'success' => false,

                    'message' =>
                        'This escrow has already been completed.',

                    'reference' =>
                        $reference

                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Find Buyer By Phone
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | The phone number is only an external identity.
            |
            | We convert it to users.id and compare that internal
            | ID against escrow.buyer_id.
            |
            */

            $buyer =
                $this->findUserByPhone(
                    $phone
                );


            Logger::write(
                'escrow_api_service',
                [
                    'step' => 'BUYER_LOOKUP',
                    'reference' => $reference,
                    'phone' => $phone,
                    'buyer_id' =>
                        $buyer['id']
                        ?? null
                ]
            );


            if (!$buyer) {

                Logger::write(
                    'escrow_api_service',
                    [
                        'step' =>
                            'BUYER_PHONE_NOT_REGISTERED',
                        'reference' => $reference,
                        'phone' => $phone
                    ]
                );


                return [

                    'success' => false,

                    'message' =>
                        'Buyer phone number is not registered.'

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
                    'step' => 'BUYER_AUTHORIZATION',
                    'reference' => $reference,
                    'expected_buyer_id' =>
                        $expectedBuyerId,
                    'actual_buyer_id' =>
                        $actualBuyerId
                ]
            );


            if (
                $expectedBuyerId <= 0
                ||
                $actualBuyerId <= 0
                ||
                $expectedBuyerId
                !==
                $actualBuyerId
            ) {

                Logger::write(
                    'escrow_api_service',
                    [
                        'step' =>
                            'BUYER_AUTHORIZATION_FAILED',
                        'reference' => $reference
                    ]
                );


                return [

                    'success' => false,

                    'message' =>
                        'This phone number is not authorized to confirm this escrow.'

                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Authorized
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'escrow_api_service',
                [
                    'step' => 'BUYER_AUTHORIZED',
                    'reference' => $reference,
                    'buyer_id' => $actualBuyerId
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Existing Escrow Confirmation Workflow
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | We do NOT duplicate buyerConfirm(), notification,
            | payout-bank workflow, admin notification, etc.
            |
            | EscrowConfirmationService remains the source of truth.
            |
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
                    'step' => 'CONFIRMATION_SERVICE_RESULT',
                    'reference' => $reference,
                    'buyer_id' => $actualBuyerId,
                    'result' => $result
                ]
            );


            if (
                !is_array($result)
            ) {

                return [

                    'success' => false,

                    'message' =>
                        'Unable to confirm receipt.'

                ];
            }


            /*
            |--------------------------------------------------------------------------
            | Return Existing Workflow Result
            |--------------------------------------------------------------------------
            */

            return $result;

        }
        catch (Throwable $e) {

            Logger::write(
                'escrow_api_service_error',
                [
                    'step' => 'CONFIRM_RECEIPT_EXCEPTION',
                    'reference' => $reference,
                    'phone' => $phone,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );


            return [

                'success' => false,

                'message' =>
                    'Unable to confirm receipt.'

            ];
        }
    }


    /**
     * ---------------------------------------------------------
     * Find User By Phone
     * ---------------------------------------------------------
     *
     * The users table may store phone values in different
     * formats depending on the platform.
     *
     * We first attempt exact matches against common formats.
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
                \Core\Database
                    ::getInstance()
                    ->connection();


            /*
            |--------------------------------------------------------------------------
            | Try Exact Phone
            |--------------------------------------------------------------------------
            */

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
                    $phone
                ]
            );


            $user =
                $stmt->fetch();


            if ($user) {

                return $user;
            }


            /*
            |--------------------------------------------------------------------------
            | Try Nigerian +234 Format
            |--------------------------------------------------------------------------
            */

            if (
                str_starts_with(
                    $phone,
                    '234'
                )
            ) {

                $local =
                    '0'
                    .
                    substr(
                        $phone,
                        3
                    );


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
                        $local
                    ]
                );


                $user =
                    $stmt->fetch();


                if ($user) {

                    return $user;
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Try Local Nigerian Phone
            |--------------------------------------------------------------------------
            */

            if (
                str_starts_with(
                    $phone,
                    '0'
                )
            ) {

                $international =
                    '234'
                    .
                    substr(
                        $phone,
                        1
                    );


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
                        $international
                    ]
                );


                $user =
                    $stmt->fetch();


                if ($user) {

                    return $user;
                }
            }


            return null;

        }
        catch (Throwable $e) {

            Logger::write(
                'escrow_api_service_error',
                [
                    'step' => 'FIND_USER_BY_PHONE_FAILED',
                    'phone' => $phone,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            );


            return null;
        }
    }


    /**
     * ---------------------------------------------------------
     * Normalize Phone
     * ---------------------------------------------------------
     */
    protected function normalizePhone(
        string $phone
    ): string {

        $phone =
            trim($phone);


        /*
        |--------------------------------------------------------------------------
        | Remove WhatsApp Prefix
        |--------------------------------------------------------------------------
        */

        $phone =
            str_replace(
                'whatsapp:',
                '',
                $phone
            );


        /*
        |--------------------------------------------------------------------------
        | Remove +
        |--------------------------------------------------------------------------
        */

        $phone =
            str_replace(
                '+',
                '',
                $phone
            );


        /*
        |--------------------------------------------------------------------------
        | Remove spaces / brackets / hyphens
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
        | Convert Nigerian Local Number
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
     * Amount
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
        ) {

            return null;
        }


        return (float)$value;
    }


    /**
     * ---------------------------------------------------------
     * Item Name
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
     * Seller Name
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
     * Seller Verification
     * ---------------------------------------------------------
     */
    protected function sellerVerified(
        ?array $seller
    ): bool {

        if (!$seller) {

            return false;
        }


        /*
        |--------------------------------------------------------------------------
        | Use existing verification field when available.
        |--------------------------------------------------------------------------
        */

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


        /*
        |--------------------------------------------------------------------------
        | Do not assume verification.
        |--------------------------------------------------------------------------
        */

        return false;
    }
}