<?php

declare(strict_types=1);

namespace Services\Escrow;

use Core\Database;
use Core\Logger;
use Throwable;

class EscrowSettings
{
    /**
     * ---------------------------------------------------------
     * Database Settings Keys
     * ---------------------------------------------------------
     */

    public const AUTO_PAYOUT_KEY =
        'escrow_auto_payout';

    public const REQUIRE_ADMIN_APPROVAL_KEY =
        'escrow_require_admin_approval';

    public const ENABLE_REFUNDS_KEY =
        'escrow_enable_refunds';

    public const ENABLE_DISPUTES_KEY =
        'escrow_enable_disputes';

    public const ENABLE_HOLDS_KEY =
        'escrow_enable_holds';


    /*
    |--------------------------------------------------------------------------
    | ESCROW FEE
    |--------------------------------------------------------------------------
    */

    public const FEE_ENABLED_KEY =
        'escrow_fee_enabled';

    public const FEE_TYPE_KEY =
        'escrow_fee_type';

    public const FEE_PERCENTAGE_KEY =
        'escrow_fee_percentage';

    public const FEE_FIXED_KEY =
        'escrow_fee_fixed';

    public const FEE_PAYER_KEY =
        'escrow_fee_payer';


    /*
    |--------------------------------------------------------------------------
    | PAYSTACK FEE
    |--------------------------------------------------------------------------
    */

    public const PAYSTACK_FEE_ENABLED_KEY =
        'escrow_paystack_fee_enabled';

    public const PAYSTACK_FEE_TYPE_KEY =
        'escrow_paystack_fee_type';

    public const PAYSTACK_FEE_PERCENTAGE_KEY =
        'escrow_paystack_fee_percentage';

    public const PAYSTACK_FEE_FIXED_KEY =
        'escrow_paystack_fee_fixed';

    public const PAYSTACK_FEE_PAYER_KEY =
        'escrow_paystack_fee_payer';


    /**
     * ---------------------------------------------------------
     * Get Setting
     * ---------------------------------------------------------
     */
    public static function get(
        string $key,
        mixed $default = null
    ): mixed {

        try {

            $db = Database::getInstance();

            $stmt = $db->connection()->prepare(
                "
                SELECT value
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

                Logger::write(
                    'escrow_settings',
                    [
                        'step'    => 'SETTING_NOT_FOUND',
                        'key'     => $key,
                        'default' => $default
                    ]
                );

                return $default;
            }

            return $value;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_settings_error',
                [
                    'step'    => 'GET_SETTING_EXCEPTION',
                    'key'     => $key,
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine()
                ]
            );

            return $default;
        }
    }


    /**
     * ---------------------------------------------------------
     * Get Boolean Setting
     * ---------------------------------------------------------
     */
    public static function bool(
        string $key,
        bool $default = false
    ): bool {

        $value = self::get(
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


    /**
     * ---------------------------------------------------------
     * Get Float Setting
     * ---------------------------------------------------------
     */
    public static function float(
        string $key,
        float $default = 0.0
    ): float {

        $value = self::get(
            $key,
            $default
        );

        if (!is_numeric($value)) {

            return $default;
        }

        return (float)$value;
    }


    /**
     * ---------------------------------------------------------
     * Get String Setting
     * ---------------------------------------------------------
     */
    public static function string(
        string $key,
        string $default = ''
    ): string {

        return trim(
            (string)self::get(
                $key,
                $default
            )
        );
    }


    /**
     * ---------------------------------------------------------
     * Automatic Payout
     * ---------------------------------------------------------
     */
    public static function autoPayout(): bool
    {
        return self::bool(
            self::AUTO_PAYOUT_KEY,
            false
        );
    }


    /**
     * ---------------------------------------------------------
     * Require Admin Approval
     * ---------------------------------------------------------
     */
    public static function requireAdminApproval(): bool
    {
        return self::bool(
            self::REQUIRE_ADMIN_APPROVAL_KEY,
            true
        );
    }


    /**
     * ---------------------------------------------------------
     * Refunds Enabled
     * ---------------------------------------------------------
     */
    public static function refundsEnabled(): bool
    {
        return self::bool(
            self::ENABLE_REFUNDS_KEY,
            true
        );
    }


    /**
     * ---------------------------------------------------------
     * Disputes Enabled
     * ---------------------------------------------------------
     */
    public static function disputesEnabled(): bool
    {
        return self::bool(
            self::ENABLE_DISPUTES_KEY,
            true
        );
    }


    /**
     * ---------------------------------------------------------
     * Holds Enabled
     * ---------------------------------------------------------
     */
    public static function holdsEnabled(): bool
    {
        return self::bool(
            self::ENABLE_HOLDS_KEY,
            true
        );
    }


    /**
     * ---------------------------------------------------------
     * Escrow Fee Enabled
     * ---------------------------------------------------------
     */
    public static function feeEnabled(): bool
    {
        return self::bool(
            self::FEE_ENABLED_KEY,
            true
        );
    }


    /**
     * ---------------------------------------------------------
     * Escrow Fee Type
     *
     * percentage
     * fixed
     * percentage_plus_fixed
     * ---------------------------------------------------------
     */
    public static function feeType(): string
    {
        return self::string(
            self::FEE_TYPE_KEY,
            'percentage'
        );
    }


    /**
     * ---------------------------------------------------------
     * Escrow Fee Percentage
     * ---------------------------------------------------------
     */
    public static function feePercentage(): float
    {
        return self::float(
            self::FEE_PERCENTAGE_KEY,
            0.0
        );
    }


    /**
     * ---------------------------------------------------------
     * Escrow Fixed Fee
     * ---------------------------------------------------------
     */
    public static function feeFixed(): float
    {
        return self::float(
            self::FEE_FIXED_KEY,
            0.0
        );
    }


    /**
     * ---------------------------------------------------------
     * Escrow Fee Payer
     *
     * buyer
     * seller
     * split
     * ---------------------------------------------------------
     */
    public static function feePayer(): string
    {
        return self::string(
            self::FEE_PAYER_KEY,
            'buyer'
        );
    }


    /**
     * ---------------------------------------------------------
     * Paystack Fee Enabled
     * ---------------------------------------------------------
     */
    public static function paystackFeeEnabled(): bool
    {
        return self::bool(
            self::PAYSTACK_FEE_ENABLED_KEY,
            true
        );
    }


    /**
     * ---------------------------------------------------------
     * Paystack Fee Type
     * ---------------------------------------------------------
     */
    public static function paystackFeeType(): string
    {
        return self::string(
            self::PAYSTACK_FEE_TYPE_KEY,
            'percentage_plus_fixed'
        );
    }


    /**
     * ---------------------------------------------------------
     * Paystack Fee Percentage
     * ---------------------------------------------------------
     */
    public static function paystackFeePercentage(): float
    {
        return self::float(
            self::PAYSTACK_FEE_PERCENTAGE_KEY,
            0.0
        );
    }


    /**
     * ---------------------------------------------------------
     * Paystack Fixed Fee
     * ---------------------------------------------------------
     */
    public static function paystackFeeFixed(): float
    {
        return self::float(
            self::PAYSTACK_FEE_FIXED_KEY,
            0.0
        );
    }


    /**
     * ---------------------------------------------------------
     * Paystack Fee Payer
     * ---------------------------------------------------------
     */
    public static function paystackFeePayer(): string
    {
        return self::string(
            self::PAYSTACK_FEE_PAYER_KEY,
            'buyer'
        );
    }
}